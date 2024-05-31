<?php
if (count($argv) !== 3) {
	echo "Usage: " . $argv[0] . " result.json output.jsonl\n";
	exit(1);
}
ini_set('memory_limit', '-1');
$inputFile = $argv[1];
$outputFile = $argv[2];
echo "Reading $inputFile...\n";
$messagesJSON = json_decode(file_get_contents($inputFile), true);
echo "Building message tree...\n";
foreach ($messagesJSON['messages'] as $message) {
	$messagesById[$message['id']] = $message;
}
unset($messagesJSON);

$outputHandle = fopen($outputFile, 'wb');
echo "Finding messages that are replies and recording them into $outputFile...\n";
//fwrite($outputHandle, '{"data": [');
foreach ($messagesById as $message) {
	$chain = getMessageChain($message);
	if(count($chain) < 2) {
		continue;
	}

	$text = readMessageText($message);
	if (trim($text) === '') {
		echo "Skipping empty message\n";
		continue;
	}

	$human = count($chain) % 2 === 0;
	$result = [];
	foreach ($chain as $chainMessage) {
		if (!array_key_exists('from', $chainMessage)) {
			echo "Skipping message without an author\n";
			continue 2;
		}
		$author = $chainMessage['from'];
		if ($author === 'Виктор89' || empty($author)) {
			continue 2;
		}
		$messageText = readMessageText($chainMessage);
        $newRow = [
            'content' => $messageText,
            'author' => $author,
            'role' => $human ? 'user' : 'assistant',
            'source' => 'siepatchdb',
        ];
		$human = !$human;
        $result[] = $newRow;
	}
	$results[] = $result;
}
fwrite($outputHandle, json_encode($results));
//fwrite($outputHandle, ']}');
function getMessageChain($message): array {
	global $messagesById;
	if (!isset($message['reply_to_message_id'])) {
		return [$message];
	}
	if (!array_key_exists($message['reply_to_message_id'], $messagesById)) {
		echo "Source message not found!\n";
		return [$message];
	}

	$sourceMessage = $messagesById[$message['reply_to_message_id']];

	return array_merge(getMessageChain($sourceMessage), [$message]);

}
function readMessageText($message)
{
	$text = '';
	foreach ($message['text_entities'] as $textEntity) {
		$text .= $textEntity['text'];
	}
	return $text;
}
