<?php
/*
 * Simple SMS gateway using Android ADB
 * @author: Alouit Alexandre <alexandre.alouit@gmail.com>
 */

$config = json_decode(file_get_contents(__DIR__ . '/config.json'), true);

/*
* compute signature
* @params: (array) data
* @return: (string) signature
*/
function signature($data, $config)
{
    if (!is_array($data))
        return false;

    ksort($data);

    $signature = array($config['endpoint']);

    foreach ($data as $key => $value) {
        $signature[] = sprintf('%s=%s', $key, $value);
    }

    unset($data);

    $signature[] = $config['token'];

    $signature = implode(",", $signature);

    return base64_encode(sha1($signature, true));
}

/*
* do request
* @params: (array) data
* @return: (string) response / (bool) state
*/
function request($data, $config)
{
    $data['phone_number'] = $config['phonenumber'];
    $signature = signature($data, $config);
    $data = http_build_query($data);

    $context = array(
        'http' => array(
            'method' => 'POST',
            'ignore_errors' => true,
            'timeout' => 5,
            'header' => array(
                "Content-Type: application/x-www-form-urlencoded",
                "User-Agent: " . $config['user-agent'],
                "x-request-signature: " . $signature,
                "Content-Length: " . strlen($data)
            ),
            'content' => $data
        )
    );

    return @file_get_contents($config['endpoint'], false, stream_context_create($context));
}

$result = request(
    array(
        'action' => 'outgoing'
    ),
    $config
);

if (!$result = json_decode($result, true))
    return false;

// verify phone number
$phonenumber = "+";
$phonenumber .= exec(
    "/usr/local/bin/adb shell service call iphonesubinfo 17 | awk '{print $6}' | grep -o '[0-9]' | tr -d '\n'"
);

if ($config['phonenumber'] != $phonenumber) {
    print('invalid phone number');
    return;
}

foreach ($result as $events) {
    foreach ($events as $event) {
        switch (@$event['event']) {
                // send a message
            case 'send':

                foreach ($event['messages'] as $message) {
                    // send queue request
                    request(
                        array(
                            'action' => 'send_status',
                            'status' => 'queued',
                            'id' => $message['id']
                        ),
                        $config
                    );

                    // send the sms
                    $output = exec(
                        '/usr/local/bin/adb shell service call isms 7 i32 0 s16 "null" ' .
                            's16 "' . $message['to'] . '" s16 "null" s16 "\'' . $message['message'] . '\'"'
                    );

                    if ($output != "Result: Parcel(00000000    '....')")
                        continue;

                    // set as send
                    request(
                        array(
                            'action' => 'send_status',
                            'status' => 'sent',
                            'id' => $message['id']
                        ),
                        $config
                    );
                }

                break;
        }
    }
}
