<?php
if (count($argv) !== 3) {
	echo "Usage: " . $argv[0] . " result.json output.txt\n";
	exit(1);
}
ini_set('memory_limit', '-1');
$inputFile = $argv[1];
$outputFile = $argv[2];
echo "Reading $inputFile...\n";
$messagesJSON = json_decode(file_get_contents($inputFile), true);
echo "Building message array by id..\n";
$messagesById = [];
foreach ($messagesJSON['messages'] as $message) {
	$messagesById[$message['id']] = $message;
}
unset($messagesJSON);

$outputHandle = fopen($outputFile, 'wb');
echo "Writing messages to $outputFile...\n";

foreach ($messagesById as $message) {
    if (!shouldMessageBeIncluded($message)) {
        continue;
    }
    $line = $message['from'] . ': ' . readMessageText($message) . "\n";
    fwrite($outputHandle, $line);
}
function shouldMessageBeIncluded($chainMessage):bool {
    if (!array_key_exists('from', $chainMessage)) {
//            echo "Skipping message without an author\n";
        return false;
    }
    $author = $chainMessage['from'];
    if ($author === 'Виктор89' || empty($author)) {
        return false;
    }
    $messageText = readMessageText($chainMessage);
    if (str_contains('Viktor89_bot', $messageText)) {
        return false;
    }

    if (trim($messageText) === '') {
        echo "Skipping message because of an empty message\n";
        return false;
    }

    return true;
}
function readMessageText($message)
{
	$text = '';
	foreach ($message['text_entities'] as $textEntity) {
		$text .= $textEntity['text'];
	}
	return $text;
}
