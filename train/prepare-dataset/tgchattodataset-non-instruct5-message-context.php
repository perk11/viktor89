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
$messagesbyId= [];
$messagesByIndex = [];
foreach ($messagesJSON['messages'] as $message) {
	$messagesById[$message['id']] = $message;
	$messagesByIndex[] = $message;
}
unset($messagesJSON);
$authorReplacement = [
  'Nanak0n 89' => 'Nanak0n',
  'Илья Бакулин' => 'Ilya Bakulin',
  'FIL' => 'Philipp M',
];
$outputHandle = fopen($outputFile, 'wb');
echo "Finding messages that are replies and recording them into $outputFile...\n";
//fwrite($outputHandle, '{"data": [');
const MESSAGES_FOR_CONTEXT = 5;
foreach ($messagesByIndex as $messageIndex => $message) {
    $chain = getMessageChain($message);
    filterChain($chain);
    if (count($chain) > MESSAGES_FOR_CONTEXT) {
        $chain = array_slice($chain, count($chain) - MESSAGES_FOR_CONTEXT, MESSAGES_FOR_CONTEXT);
    }
    $firstMessageToAddToContextIndex = $messageIndex - 1;
    while (count($chain) < MESSAGES_FOR_CONTEXT) {
        $i = $firstMessageToAddToContextIndex;
        $firstMessageToAddToContextIndex = max($firstMessageToAddToContextIndex - MESSAGES_FOR_CONTEXT + count($chain), 0);
        for (; $i > $firstMessageToAddToContextIndex; $i--) {
            array_unshift($chain, $messagesByIndex[$i]);
        }
        filterChain($chain);
        if ($firstMessageToAddToContextIndex === 0) {
            break;
        }
    }
    if (count($chain) < MESSAGES_FOR_CONTEXT) {
        continue;
    }
    $rowText = '';
	foreach ($chain as $chainMessage) {
        $author = $chainMessage['from'];
		if (array_key_exists($author, $authorReplacement)) {
            $author = $authorReplacement[$author];
        }
		$author = str_replace(' ', '_', $author);
		$messageText = readMessageText($chainMessage);

        $rowText .= "<bot>: [$author] $messageText\n";
    }
    $rowText .= "<human>";
    $rowTexts[] = $rowText;
}

$rowTexts = array_values($rowTexts);
echo "Filtering out shorter chains...\n";
$newRowTexts = [];
foreach ($rowTexts as $index => $rowText) {
    $skipRow = false;
    $maxIndexToCheck = min(count($rowTexts) - 1, $index + 25);
    for ($i = $index+1; $i <= $maxIndexToCheck; $i++) {
        $rowText2 = $rowTexts[$i];
        if (str_contains($rowText2, $rowText)) {
            $skipRow = true;
            break;
        }
    }
    if (!$skipRow) {
        $newRowTexts[] = $rowText;
    }
}
echo "Filtering out unique messages...\n";
$newRowTexts = array_unique($newRowTexts);
foreach ($newRowTexts as $rowText) {
    fwrite($outputHandle, json_encode(['text' => $rowText], JSON_UNESCAPED_UNICODE) . "\n");
}

function filterChain(&$chain): void {
    $chain = array_filter($chain, static function($chainMessage) {
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
            echo "Skipping chain because of an empty message\n";
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
