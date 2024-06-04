<?php
if (count($argv) !== 3) {
	echo "Usage: " . $argv[0] . " result.json output.jsonl\n";
	exit(1);
}
ini_set('memory_limit', '16G');
$inputFile = $argv[1];
$outputFile = $argv[2];
echo "Reading $inputFile...\n";
$messagesJSON = json_decode(file_get_contents($inputFile), true);
echo "Building message tree...\n";
$messagesbyId= [];
$messagesByIndex = [];
foreach ($messagesJSON['messages'] as $message) {
	$messagesById[$message['id']] = $message;
	$messagesByIndex[] = $message;
}
unset($messagesJSON);
$authorReplacement = [
  'Виктор 89' => 'Nanak0n',
  'Илья Бакулин' => 'Ilya Bakulin',
  'FIL' => 'Philipp M',
];
$outputHandle = fopen($outputFile, 'wb');
echo "Finding messages that are replies and recording them into $outputFile...\n";
//fwrite($outputHandle, '[');

foreach ($messagesByIndex as $messageIndex => $message) {
    $chain = getMessageChain($message);
    filterChain($chain);
    $rowText = '';
    $human = count($chain) % 2 === 0;
    $result = [];
	foreach ($chain as $chainMessage) {
        $messageText = readMessageText($chainMessage);
        if (str_contains('Viktor89_bot', $messageText)) {
            echo "Skipping bot chain based on message text\n";
            continue 2; //exclude chains that mention the bot
        }
        $author = $chainMessage['from'];
		if (array_key_exists($author, $authorReplacement)) {
            $author = $authorReplacement[$author];
        }
		$author = str_replace(' ', '_', $author);
        if ($author === 'Виктор89') {
            echo "Skipping bot chain based on author\n";
            continue 2; //exclude chains that have messages from the bot in them
        }

        $newRow = [
            'content' => $messageText,
            'author' => $author,
            'role' => $human ? 'user' : 'assistant',
            'source' => 'siepatchdb',
        ];
        $human = !$human;
        $result[] = $newRow;
    }
    if (count($result) > 1) {
        $results[] = $result;
        fwrite($outputHandle, json_encode(['conversations' => $result], JSON_UNESCAPED_UNICODE) ."\n");

    }

}
//fseek($outputHandle, filesize($outputFile) - 2);
//fwrite($outputHandle, ']');

function filterChain(&$chain): void {
    $chain = array_filter($chain, static function($chainMessage) {
        if (!array_key_exists('from', $chainMessage)) {
//            echo "Skipping message without an author\n";
            return false;
        }
        $author = $chainMessage['from'];
        if (empty($author)) {
            return false;
        }
        $messageText = readMessageText($chainMessage);

        if (trim($messageText) === '') {
//            echo "Skipping empty message\n";
            return false;
        }

        return true;
    });
    if (count($chain) < 2) { return; }
    $chain = array_values($chain);
    $newChain = [$chain[0]];
    $currentNewChainIndex = 0;
    for ($i = 1, $iMax = count($chain); $i < $iMax; $i++) {
        if ($chain[$i]['from'] === $chain[$i - 1]['from']) {
            $newChain[$currentNewChainIndex]['text_entities'][] = ['type' => 'plain', 'text' => "\n"];
            $newChain[$currentNewChainIndex]['text_entities'] = array_merge($newChain[$currentNewChainIndex]['text_entities'], $chain[$i]['text_entities']);
        } else {
            $newChain[] = $chain[$i];
            $currentNewChainIndex++;
        }
    }
    $chain = $newChain;
}
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
