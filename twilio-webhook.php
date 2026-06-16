<?php
// Apollo PV Designs Twilio inbound SMS webhook.
// Logs replies, alerts the sales inbox, and mirrors the SMS activity into HubSpot when configured.

function clean_sms_text($value) {
    $value = is_string($value) ? $value : '';
    $value = trim($value);
    $value = str_replace(["\r", "\0"], '', $value);
    return $value;
}

function normalize_phone($phone) {
    $phone = trim((string) $phone);
    if ($phone === '') {
        return '';
    }
    if (strpos($phone, '+') === 0) {
        return preg_replace('/[^+0-9]/', '', $phone);
    }
    $digits = preg_replace('/\D+/', '', $phone);
    if (strlen($digits) === 10) {
        return '+1' . $digits;
    }
    if (strlen($digits) === 11 && strpos($digits, '1') === 0) {
        return '+' . $digits;
    }
    return $phone;
}

function xml_response($message = '') {
    header('Content-Type: text/xml; charset=utf-8');
    if ($message === '') {
        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Response></Response>";
    } else {
        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Response><Message>" . htmlspecialchars($message, ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</Message></Response>";
    }
    exit;
}

function ensure_storage_dir() {
    $storage_dir = __DIR__ . '/submissions';
    if (!is_dir($storage_dir)) {
        mkdir($storage_dir, 0755, true);
    }
    $deny_file = $storage_dir . '/.htaccess';
    if (!file_exists($deny_file)) {
        file_put_contents($deny_file, "Require all denied\n");
    }
    $index_file = $storage_dir . '/index.html';
    if (!file_exists($index_file)) {
        file_put_contents($index_file, "<!doctype html><title>Not found</title>\n");
    }
    return $storage_dir;
}

function load_twilio_config() {
    $config_path = __DIR__ . '/config/twilio.php';
    if (!file_exists($config_path)) {
        return [];
    }
    $config = include $config_path;
    return is_array($config) ? $config : [];
}

function validate_twilio_signature($auth_token, $url) {
    if ($auth_token === '') {
        return true;
    }
    $signature = $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '';
    if ($signature === '') {
        return false;
    }

    $params = $_POST;
    ksort($params);
    $data = $url;
    foreach ($params as $key => $value) {
        $data .= $key . $value;
    }
    $expected = base64_encode(hash_hmac('sha1', $data, $auth_token, true));
    return hash_equals($expected, $signature);
}

function hubspot_request($method, $path, $token, $payload = null) {
    $url = 'https://api.hubapi.com' . $path;
    $ch = curl_init($url);
    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
    ];
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $status < 200 || $status >= 300) {
        throw new Exception('HubSpot API error ' . $status . ': ' . ($error ?: $body));
    }
    return json_decode($body, true);
}

function find_or_create_hubspot_contact_by_phone($phone, $token) {
    $search = hubspot_request('POST', '/crm/v3/objects/contacts/search', $token, [
        'filterGroups' => [[
            'filters' => [[
                'propertyName' => 'phone',
                'operator' => 'EQ',
                'value' => $phone,
            ]],
        ]],
        'properties' => ['email', 'firstname', 'lastname', 'phone', 'hs_lead_status'],
        'limit' => 1,
    ]);

    if (!empty($search['results'][0]['id'])) {
        $contact_id = $search['results'][0]['id'];
        hubspot_request('PATCH', '/crm/v3/objects/contacts/' . $contact_id, $token, [
            'properties' => [
                'phone' => $phone,
                'hs_lead_status' => 'CONNECTED',
            ],
        ]);
        return $contact_id;
    }

    $created = hubspot_request('POST', '/crm/v3/objects/contacts', $token, [
        'properties' => [
            'phone' => $phone,
            'lifecyclestage' => 'lead',
            'hs_lead_status' => 'CONNECTED',
        ],
    ]);
    return $created['id'] ?? '';
}

function add_hubspot_sms_note($contact_id, $token, $from, $to, $body, $message_sid, $submitted_at) {
    if ($contact_id === '') {
        return 'missing_contact';
    }
    $note_body = "Inbound SMS reply received {$submitted_at}\n\n"
        . "From: {$from}\nTo: {$to}\nMessageSid: {$message_sid}\n\n"
        . $body;

    // HubSpot-defined association type 202 links a note to a contact.
    $note = hubspot_request('POST', '/crm/v3/objects/notes', $token, [
        'properties' => [
            'hs_timestamp' => gmdate('c'),
            'hs_note_body' => $note_body,
        ],
        'associations' => [[
            'to' => ['id' => $contact_id],
            'types' => [[
                'associationCategory' => 'HUBSPOT_DEFINED',
                'associationTypeId' => 202,
            ]],
        ]],
    ]);
    return 'note_' . ($note['id'] ?? 'created');
}

function push_sms_to_hubspot($from, $to, $body, $message_sid, $submitted_at) {
    $config_path = __DIR__ . '/config/hubspot.php';
    if (!file_exists($config_path)) {
        return 'missing_config';
    }
    $config = include $config_path;
    $token = $config['token'] ?? '';
    if ($token === '') {
        return 'missing_token';
    }
    if (!function_exists('curl_init')) {
        return 'missing_curl';
    }

    $contact_id = find_or_create_hubspot_contact_by_phone($from, $token);
    return add_hubspot_sms_note($contact_id, $token, $from, $to, $body, $message_sid, $submitted_at);
}

function send_sales_alert($from, $to, $body, $message_sid, $hubspot_status, $submitted_at) {
    $recipient = 'sales@apollopvdesign.com';
    $subject = 'Apollo PV SMS reply from ' . $from;
    $message = "Apollo PV received an inbound SMS reply.\n\n"
        . "Submitted: {$submitted_at}\n"
        . "From: {$from}\n"
        . "To: {$to}\n"
        . "MessageSid: {$message_sid}\n"
        . "HubSpot: {$hubspot_status}\n\n"
        . "Message:\n{$body}\n";
    $headers = [
        'From: Apollo PV SMS <no-reply@apollopvdesign.com>',
        'Content-Type: text/plain; charset=UTF-8',
    ];
    return mail($recipient, $subject, $message, implode("\r\n", $headers));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    xml_response('');
}

$storage_dir = ensure_storage_dir();
$config = load_twilio_config();
$auth_token = $config['auth_token'] ?? '';
$public_url = $config['webhook_url'] ?? 'https://apollopvdesign.com/twilio-webhook.php';

if (!validate_twilio_signature($auth_token, $public_url)) {
    file_put_contents($storage_dir . '/twilio-webhook-errors.log', '[' . gmdate('Y-m-d H:i:s') . ' UTC] Invalid Twilio signature from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n", FILE_APPEND);
    http_response_code(403);
    xml_response('');
}

$submitted_at = gmdate('Y-m-d H:i:s') . ' UTC';
$from = normalize_phone(clean_sms_text($_POST['From'] ?? ''));
$to = normalize_phone(clean_sms_text($_POST['To'] ?? ''));
$body = clean_sms_text($_POST['Body'] ?? '');
$message_sid = clean_sms_text($_POST['MessageSid'] ?? '');
$sms_status = clean_sms_text($_POST['SmsStatus'] ?? '');

$csv_path = $storage_dir . '/sms-messages.csv';
$is_new_file = !file_exists($csv_path);
$fh = fopen($csv_path, 'a');
if ($fh) {
    if ($is_new_file) {
        fputcsv($fh, ['submitted_at', 'direction', 'from', 'to', 'body', 'message_sid', 'sms_status']);
    }
    fputcsv($fh, [$submitted_at, 'inbound', $from, $to, $body, $message_sid, $sms_status]);
    fclose($fh);
}

$hubspot_status = 'not_attempted';
try {
    $hubspot_status = push_sms_to_hubspot($from, $to, $body, $message_sid, $submitted_at);
} catch (Exception $e) {
    $hubspot_status = 'error';
    file_put_contents($storage_dir . '/hubspot-errors.log', '[' . $submitted_at . '] SMS webhook: ' . $e->getMessage() . "\n", FILE_APPEND);
}

send_sales_alert($from, $to, $body, $message_sid, $hubspot_status, $submitted_at);

if (preg_match('/^\s*(STOP|STOPALL|UNSUBSCRIBE|CANCEL|END|QUIT)\s*$/i', $body)) {
    xml_response('');
}

xml_response('Thanks — Apollo PV received your reply. A team member will follow up shortly. Reply STOP to opt out.');
