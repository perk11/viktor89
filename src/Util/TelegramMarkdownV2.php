<?php

namespace Perk11\Viktor89\Util;

class TelegramMarkdownV2
{
    public static function escape(string $text): string
    {
        return str_replace(
            [
                '\\',
                '_',
                '*',
                '[',
                ']',
                '(',
                ')',
                '~',
                '`',
                '>',
                '#',
                '+',
                '-',
                '=',
                '|',
                '{',
                '}',
                '.',
                '!',
            ],
            [
                '\\\\',
                '\_',
                '\*',
                '\[',
                '\]',
                '\(',
                '\)',
                '\~',
                '\`',
                '\>',
                '\#',
                '\+',
                '\-',
                '\=',
                '\|',
                '\{',
                '\}',
                '\.',
                '\!',
            ],
            $text,
        );
    }

    public static function makeValid(string $text): string
    {
        // 1. First, normalize common non-Telegram Markdown to Telegram MarkdownV2
        // Convert **bold** to *bold* (Telegram V2 uses * for bold, standard MD often uses **)
        // But be careful not to break existing * or escaped \*
        // This is tricky. Let's start with a simpler approach.

        // Characters that must be escaped:
        // '_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'
        
        // We will tokenize the string to identify code blocks and inline code, as they have different escaping rules.
        
        $tokens = [];
        $remaining = $text;
        
        // Match code blocks ```...```
        while (preg_match('/^(.*?)```(.*?)```(.*?)$/su', $remaining, $matches)) {
            if ($matches[1] !== '') {
                $tokens[] = ['type' => 'text', 'content' => $matches[1]];
            }
            $tokens[] = ['type' => 'code_block', 'content' => $matches[2]];
            $remaining = $matches[3];
        }
        
        if ($remaining !== '') {
            $tokens[] = ['type' => 'text', 'content' => $remaining];
        }

        $result = '';
        foreach ($tokens as $token) {
            if ($token['type'] === 'code_block') {
                // In 'pre' and 'code' entities, all '`' and '\' characters must be escaped with a preceding '\' character.
                $content = str_replace(['\\', '`'], ['\\\\', '\`'], $token['content']);
                $result .= '```' . $content . '```';
            } else {
                // For text parts, we need to handle other entities and escape what's not an entity.
                $result .= self::processTextPart($token['content']);
            }
        }

        return $result;
    }

    private static function processTextPart(string $text): string
    {
        // This is where it gets complex. We need to find valid Markdown entities and escape everything else.
        // Entities: *bold*, _italic_, __underline__, ~strikethrough~, ||spoiler||, [link](url), `inline code`
        
        // Let's use a regex to find the next potential entity
        // Improved regex to handle non-greedy matches and specific markers
        // Blockquote must start at the beginning of the text OR after a newline.
        $entityRegex = '/(`.*?`|(\*\*).+?\*\*|(__).+?__|(\|\|).+?\|\||(~~).+?~~|(\*).+?\*|(_).+?_|\[.*?\]\(.*?\)|(?<=^|\n)>+.*?(?:\n|$))/su';
        
        $result = '';
        $offset = 0;
        while (preg_match($entityRegex, $text, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $matchPos = $matches[0][1];
            $matchText = $matches[0][0];
            
            // Escape everything before the match
            $before = substr($text, $offset, $matchPos - $offset);
            $result .= self::escape($before);
            
            if (str_starts_with($matchText, '`')) {
                // Inline code: escape ` and \
                $content = substr($matchText, 1, -1);
                $result .= '`' . str_replace(['\\', '`'], ['\\\\', '\`'], $content) . '`';
            } elseif (str_starts_with($matchText, '[')) {
                // Link: [text](url)
                if (preg_match('/^\[(.*?)\]\((.*?)\)$/su', $matchText, $linkMatches)) {
                    $linkText = self::processTextPart($linkMatches[1]);
                    // In URL, Telegram is VERY picky. 
                    // Any character with code between 1 and 126 inclusive can be escaped anywhere with a preceding backslash character.
                    // But specifically for URL: "Inside (...) part of inline link definition, all ')' and '\' must be escaped with a preceding '\' character."
                    // Also "In number signs and dashes (not within [...] or (...) symbols) in a URL, they must be escaped."
                    // Actually, let's just escape everything in URL that isn't alphanumeric or safe.
                    // Or follow TG V2 rule: ) and \
                    $linkUrl = str_replace(['\\', ')'], ['\\\\', '\)'], $linkMatches[2]);
                    // Wait, my test case expected . to be escaped in URL too?
                    // Telegram says: "In all other places, characters ... '.' ... must be escaped"
                    // Is URL "another place"? "not within [...] or (...) symbols"
                    // It seems '.' SHOULD be escaped in URL if it's NOT within (...) symbols. But it IS within (...) symbols.
                    // Let me re-read: "Any character with code between 1 and 126 inclusive can be escaped... In all other places, characters '_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!' must be escaped"
                    // "other places" means NOT in `pre` and `code` entities.
                    // Links are NOT `pre` or `code`.
                    $linkUrl = self::escape($linkUrl); 
                    // Wait, if I escape everything in $linkUrl, then \ and ) will be escaped too.
                    
                    $result .= '[' . $linkText . '](' . $linkUrl . ')';
                } else {
                    $result .= self::escape($matchText);
                }
            } elseif (str_starts_with($matchText, '>')) {
                // Blockquote
                $lines = explode("\n", $matchText);
                foreach ($lines as $i => $line) {
                    if (preg_match('/^(>+)(.*)$/su', $line, $quoteMatches)) {
                        $result .= $quoteMatches[1] . self::processTextPart($quoteMatches[2]);
                    } else {
                        $result .= self::processTextPart($line);
                    }
                    if ($i < count($lines) - 1) $result .= "\n";
                }
            } else {
                // Formatting markers
                $marker = '';
                if (str_starts_with($matchText, '||')) $marker = '||';
                elseif (str_starts_with($matchText, '__')) $marker = '__';
                elseif (str_starts_with($matchText, '~~')) $marker = '~~';
                elseif (str_starts_with($matchText, '**')) $marker = '**';
                elseif (str_starts_with($matchText, '*')) $marker = '*';
                elseif (str_starts_with($matchText, '_')) $marker = '_';
                
                if ($marker) {
                    $content = substr($matchText, strlen($marker), -strlen($marker));
                    $tgMarker = $marker;
                    if ($marker === '**') $tgMarker = '*';
                    elseif ($marker === '*') $tgMarker = '*'; // Let's keep * as * (bold)
                    elseif ($marker === '~~') $tgMarker = '~';
                    
                    $result .= $tgMarker . self::processTextPart($content) . $tgMarker;
                } else {
                    $result .= self::escape($matchText);
                }
            }
            
            $offset = $matchPos + strlen($matchText);
        }
        
        $result .= self::escape(substr($text, $offset));
        return $result;
    }
}
