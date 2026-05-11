<?php

namespace Perk11\Viktor89\Util;

class TelegramMarkdownV2
{
    private const ENCODING = 'UTF-8';

    private const RESERVED_CHARACTERS = '_*[]()~`>#+-=|{}.!';

    private const FORMATTING_MARKERS = [
        '||' => '||',
        '__' => '__',
        '~~' => '~',
        '**' => '*',
        '~' => '~',
        '*' => '*',
        '_' => '_',
    ];
    private const LATEX_COMMAND_REPLACEMENTS = [
        ',' => ' ',
        ':' => ' ',
        ';' => ' ',
        '!' => '',
        ' ' => ' ',
        '{' => '{',
        '}' => '}',
        '_' => '_',
        '%' => '%',
        '$' => '$',
        '#' => '#',
        '&' => '&',
        '|' => '‖',

        'alpha' => 'α',
        'beta' => 'β',
        'gamma' => 'γ',
        'delta' => 'δ',
        'epsilon' => 'ε',
        'varepsilon' => 'ε',
        'zeta' => 'ζ',
        'eta' => 'η',
        'theta' => 'θ',
        'vartheta' => 'ϑ',
        'iota' => 'ι',
        'kappa' => 'κ',
        'lambda' => 'λ',
        'mu' => 'μ',
        'nu' => 'ν',
        'xi' => 'ξ',
        'pi' => 'π',
        'varpi' => 'ϖ',
        'rho' => 'ρ',
        'varrho' => 'ϱ',
        'sigma' => 'σ',
        'varsigma' => 'ς',
        'tau' => 'τ',
        'upsilon' => 'υ',
        'phi' => 'φ',
        'varphi' => 'φ',
        'chi' => 'χ',
        'psi' => 'ψ',
        'omega' => 'ω',

        'Gamma' => 'Γ',
        'Delta' => 'Δ',
        'Theta' => 'Θ',
        'Lambda' => 'Λ',
        'Xi' => 'Ξ',
        'Pi' => 'Π',
        'Sigma' => 'Σ',
        'Upsilon' => 'Υ',
        'Phi' => 'Φ',
        'Psi' => 'Ψ',
        'Omega' => 'Ω',

        'pm' => '±',
        'mp' => '∓',
        'times' => '×',
        'cdot' => '·',
        'ast' => '∗',
        'star' => '⋆',
        'circ' => '∘',
        'bullet' => '∙',
        'div' => '÷',
        'over' => '⁄',

        'le' => '≤',
        'leq' => '≤',
        'ge' => '≥',
        'geq' => '≥',
        'neq' => '≠',
        'ne' => '≠',
        'equiv' => '≡',
        'sim' => '∼',
        'simeq' => '≃',
        'approx' => '≈',
        'propto' => '∝',
        'll' => '≪',
        'gg' => '≫',

        'infty' => '∞',
        'partial' => '∂',
        'nabla' => '∇',
        'forall' => '∀',
        'exists' => '∃',
        'nexists' => '∄',
        'neg' => '¬',
        'lnot' => '¬',
        'not' => '¬',

        'in' => '∈',
        'notin' => '∉',
        'ni' => '∋',
        'subset' => '⊂',
        'supset' => '⊃',
        'subseteq' => '⊆',
        'supseteq' => '⊇',
        'cup' => '∪',
        'cap' => '∩',
        'setminus' => '∖',
        'emptyset' => '∅',
        'varnothing' => '∅',

        'land' => '∧',
        'wedge' => '∧',
        'lor' => '∨',
        'vee' => '∨',
        'oplus' => '⊕',
        'otimes' => '⊗',

        'sum' => '∑',
        'prod' => '∏',
        'coprod' => '∐',
        'int' => '∫',
        'iint' => '∫∫',
        'iiint' => '∫∫∫',
        'oint' => '∮',

        'lim' => 'lim',
        'sin' => 'sin',
        'cos' => 'cos',
        'tan' => 'tan',
        'cot' => 'cot',
        'sec' => 'sec',
        'csc' => 'csc',
        'log' => 'log',
        'ln' => 'ln',
        'exp' => 'exp',
        'min' => 'min',
        'max' => 'max',
        'sup' => 'sup',
        'inf' => 'inf',
        'arg' => 'arg',
        'deg' => 'deg',
        'mod' => ' mod ',

        'to' => '→',
        'rightarrow' => '→',
        'leftarrow' => '←',
        'leftrightarrow' => '↔',
        'Rightarrow' => '⇒',
        'Leftarrow' => '⇐',
        'Leftrightarrow' => '⇔',
        'implies' => '⇒',
        'iff' => '⇔',
        'mapsto' => '↦',
        'uparrow' => '↑',
        'downarrow' => '↓',

        'ldots' => '…',
        'cdots' => '⋯',
        'dots' => '…',
        'vdots' => '⋮',
        'ddots' => '⋱',

        'angle' => '∠',
        'degree' => '°',
        'prime' => '′',
        'parallel' => '∥',
        'perp' => '⊥',
        'therefore' => '∴',
        'because' => '∵',
        'Re' => 'ℜ',
        'Im' => 'ℑ',
        'aleph' => 'ℵ',
        'hbar' => 'ℏ',

        'langle' => '⟨',
        'rangle' => '⟩',
        'lceil' => '⌈',
        'rceil' => '⌉',
        'lfloor' => '⌊',
        'rfloor' => '⌋',

        'quad' => ' ',
        'qquad' => '  ',
    ];

    private const LATEX_SUPERSCRIPT_CHARACTERS = [
        '0' => '⁰', '1' => '¹', '2' => '²', '3' => '³', '4' => '⁴',
        '5' => '⁵', '6' => '⁶', '7' => '⁷', '8' => '⁸', '9' => '⁹',
        '+' => '⁺', '-' => '⁻', '=' => '⁼', '(' => '⁽', ')' => '⁾',
        'a' => 'ᵃ', 'b' => 'ᵇ', 'c' => 'ᶜ', 'd' => 'ᵈ', 'e' => 'ᵉ',
        'f' => 'ᶠ', 'g' => 'ᵍ', 'h' => 'ʰ', 'i' => 'ⁱ', 'j' => 'ʲ',
        'k' => 'ᵏ', 'l' => 'ˡ', 'm' => 'ᵐ', 'n' => 'ⁿ', 'o' => 'ᵒ',
        'p' => 'ᵖ', 'r' => 'ʳ', 's' => 'ˢ', 't' => 'ᵗ', 'u' => 'ᵘ',
        'v' => 'ᵛ', 'w' => 'ʷ', 'x' => 'ˣ', 'y' => 'ʸ', 'z' => 'ᶻ',
        'A' => 'ᴬ', 'B' => 'ᴮ', 'D' => 'ᴰ', 'E' => 'ᴱ', 'G' => 'ᴳ',
        'H' => 'ᴴ', 'I' => 'ᴵ', 'J' => 'ᴶ', 'K' => 'ᴷ', 'L' => 'ᴸ',
        'M' => 'ᴹ', 'N' => 'ᴺ', 'O' => 'ᴼ', 'P' => 'ᴾ', 'R' => 'ᴿ',
        'T' => 'ᵀ', 'U' => 'ᵁ', 'V' => 'ⱽ', 'W' => 'ᵂ',
        'α' => 'ᵅ', 'β' => 'ᵝ', 'γ' => 'ᵞ', 'δ' => 'ᵟ', 'ε' => 'ᵋ',
        'θ' => 'ᶿ', 'ι' => 'ᶥ', 'φ' => 'ᵠ', 'χ' => 'ᵡ',
    ];

    private const LATEX_SUBSCRIPT_CHARACTERS = [
        '0' => '₀', '1' => '₁', '2' => '₂', '3' => '₃', '4' => '₄',
        '5' => '₅', '6' => '₆', '7' => '₇', '8' => '₈', '9' => '₉',
        '+' => '₊', '-' => '₋', '=' => '₌', '(' => '₍', ')' => '₎',
        'a' => 'ₐ', 'e' => 'ₑ', 'h' => 'ₕ', 'i' => 'ᵢ', 'j' => 'ⱼ',
        'k' => 'ₖ', 'l' => 'ₗ', 'm' => 'ₘ', 'n' => 'ₙ', 'o' => 'ₒ',
        'p' => 'ₚ', 'r' => 'ᵣ', 's' => 'ₛ', 't' => 'ₜ', 'u' => 'ᵤ',
        'v' => 'ᵥ', 'x' => 'ₓ',
        'β' => 'ᵦ', 'γ' => 'ᵧ', 'ρ' => 'ᵨ', 'φ' => 'ᵩ', 'χ' => 'ᵪ',
    ];

    private const LATEX_ACCENTS = [
        'hat' => '̂',
        'widehat' => '̂',
        'bar' => '̄',
        'overline' => '̅',
        'vec' => '⃗',
        'tilde' => '̃',
        'widetilde' => '̃',
        'dot' => '̇',
        'ddot' => '̈',
        'underline' => '̲',
    ];

    private const LATEX_VULGAR_FRACTIONS = [
        '1/2' => '½',
        '1/3' => '⅓',
        '2/3' => '⅔',
        '1/4' => '¼',
        '3/4' => '¾',
        '1/5' => '⅕',
        '2/5' => '⅖',
        '3/5' => '⅗',
        '4/5' => '⅘',
        '1/6' => '⅙',
        '5/6' => '⅚',
        '1/8' => '⅛',
        '3/8' => '⅜',
        '5/8' => '⅝',
        '7/8' => '⅞',
    ];

    private const LATEX_STYLED_CHARACTERS = [
        'mathbb' => [
            'A' => '𝔸', 'B' => '𝔹', 'C' => 'ℂ', 'D' => '𝔻', 'E' => '𝔼',
            'F' => '𝔽', 'G' => '𝔾', 'H' => 'ℍ', 'I' => '𝕀', 'J' => '𝕁',
            'K' => '𝕂', 'L' => '𝕃', 'M' => '𝕄', 'N' => 'ℕ', 'O' => '𝕆',
            'P' => 'ℙ', 'Q' => 'ℚ', 'R' => 'ℝ', 'S' => '𝕊', 'T' => '𝕋',
            'U' => '𝕌', 'V' => '𝕍', 'W' => '𝕎', 'X' => '𝕏', 'Y' => '𝕐',
            'Z' => 'ℤ',
            '0' => '𝟘', '1' => '𝟙', '2' => '𝟚', '3' => '𝟛', '4' => '𝟜',
            '5' => '𝟝', '6' => '𝟞', '7' => '𝟟', '8' => '𝟠', '9' => '𝟡',
        ],
    ];

    public static function escape(string $text): string
    {
        return self::escapeMarkdownText($text);
    }

    public static function makeValid(string $text): string
    {
        $result = '';
        $offset = 0;
        $length = self::length($text);
        $codeBlockMarkerLength = self::length('```');

        while ($offset < $length) {
            $openingPosition = self::findUnescaped($text, '```', $offset);

            if ($openingPosition === null) {
                $result .= self::processTextPart(self::slice($text, $offset));
                break;
            }

            $closingPosition = self::findUnescaped($text, '```', $openingPosition + $codeBlockMarkerLength);

            if ($closingPosition === null) {
                $result .= self::processTextPart(self::slice($text, $offset));
                break;
            }

            $result .= self::processTextPart(self::slice($text, $offset, $openingPosition - $offset));
            $result .= '```' . self::escapeCodeContent(self::slice($text, $openingPosition + $codeBlockMarkerLength, $closingPosition - $openingPosition - $codeBlockMarkerLength)) . '```';
            $offset = $closingPosition + $codeBlockMarkerLength;
        }

        return $result;
    }

    private static function processTextPart(string $text): string
    {
        $result = '';
        $plainTextStart = 0;
        $index = 0;
        $length = self::length($text);

        while ($index < $length) {
            if (self::isEscapedAt($text, $index)) {
                $index++;
                continue;
            }

            if (self::isBlockquoteStart($text, $index)) {
                $lineEndPosition = self::position($text, "\n", $index);
                $lineEndPosition = $lineEndPosition === null ? $length : $lineEndPosition;

                $result .= self::escapeMarkdownText(self::slice($text, $plainTextStart, $index - $plainTextStart));
                $result .= self::processBlockquoteLine(self::slice($text, $index, $lineEndPosition - $index));

                if ($lineEndPosition < $length) {
                    $result .= "\n";
                    $index = $lineEndPosition + 1;
                } else {
                    $index = $lineEndPosition;
                }

                $plainTextStart = $index;
                continue;
            }

            if (self::charAt($text, $index) === '`') {
                $inlineCode = self::parseInlineCode($text, $index);

                if ($inlineCode !== null) {
                    $result .= self::escapeMarkdownText(self::slice($text, $plainTextStart, $index - $plainTextStart));
                    $result .= $inlineCode['text'];
                    $index = $inlineCode['end'];
                    $plainTextStart = $index;
                    continue;
                }
            }

            if (self::charAt($text, $index) === '[') {
                $link = self::parseLink($text, $index);

                if ($link !== null) {
                    $result .= self::escapeMarkdownText(self::slice($text, $plainTextStart, $index - $plainTextStart));
                    $result .= $link['text'];
                    $index = $link['end'];
                    $plainTextStart = $index;
                    continue;
                }
            }
            $latexFormula = self::parseLatexFormula($text, $index);

            if ($latexFormula !== null) {
                $result .= self::escapeMarkdownText(self::slice($text, $plainTextStart, $index - $plainTextStart));
                $result .= $latexFormula['text'];
                $index = $latexFormula['end'];
                $plainTextStart = $index;
                continue;
            }

            $formattingEntity = self::parseFormattingEntity($text, $index);

            if ($formattingEntity !== null) {
                $result .= self::escapeMarkdownText(self::slice($text, $plainTextStart, $index - $plainTextStart));
                $result .= $formattingEntity['text'];
                $index = $formattingEntity['end'];
                $plainTextStart = $index;
                continue;
            }

            $index++;
        }

        $result .= self::escapeMarkdownText(self::slice($text, $plainTextStart));

        return $result;
    }

    /** @return array{text: string, end: int}|null */
    private static function parseInlineCode(string $text, int $openingPosition): ?array
    {
        $markerLength = self::countRepeatedCharacter($text, $openingPosition, '`');

        if ($markerLength === 1 && !self::canOpenInlineCode($text, $openingPosition)) {
            return null;
        }

        $marker = str_repeat('`', $markerLength);
        $closingPosition = self::findClosingInlineCodeMarker($text, $marker, $openingPosition + $markerLength);

        if ($closingPosition === null) {
            return null;
        }

        $content = self::slice($text, $openingPosition + $markerLength, $closingPosition - $openingPosition - $markerLength);

        if (!self::hasNonWhitespace($content)) {
            return null;
        }

        if ($markerLength > 1) {
            $content = self::normalizeCommonMarkCodeSpanContent($content);
        }

        return [
            'text' => '`' . self::escapeCodeContent($content) . '`',
            'end' => $closingPosition + $markerLength,
        ];
    }

    private static function findClosingInlineCodeMarker(string $text, string $marker, int $offset): ?int
    {
        $lineEndPosition = self::position($text, "\n", $offset);
        $searchLimit = $lineEndPosition ?? self::length($text);
        $searchOffset = $offset;
        $markerLength = self::length($marker);

        while (true) {
            $closingPosition = self::findUnescaped($text, $marker, $searchOffset);

            if ($closingPosition === null || $closingPosition >= $searchLimit) {
                return null;
            }

            if ($markerLength > 1 || self::canCloseInlineCode($text, $closingPosition)) {
                return $closingPosition;
            }

            $searchOffset = $closingPosition + 1;
        }
    }

    private static function canOpenInlineCode(string $text, int $openingPosition): bool
    {
        $nextCharacter = self::charAt($text, $openingPosition + 1);

        return $nextCharacter !== '' && !self::isWhitespace($nextCharacter);
    }

    private static function canCloseInlineCode(string $text, int $closingPosition): bool
    {
        $previousCharacter = self::charAt($text, $closingPosition - 1);

        return $previousCharacter !== '' && !self::isWhitespace($previousCharacter);
    }

    /** @return array{text: string, end: int}|null */
    private static function parseLink(string $text, int $openingBracketPosition): ?array
    {
        $closingBracketPosition = self::findClosingLinkBracket($text, $openingBracketPosition + 1);

        if ($closingBracketPosition === null || self::charAt($text, $closingBracketPosition + 1) !== '(') {
            return null;
        }

        $closingParenthesisPosition = self::findClosingLinkParenthesis($text, $closingBracketPosition + 2);

        if ($closingParenthesisPosition === null) {
            return null;
        }

        $linkText = self::slice($text, $openingBracketPosition + 1, $closingBracketPosition - $openingBracketPosition - 1);
        $linkUrl = self::slice($text, $closingBracketPosition + 2, $closingParenthesisPosition - $closingBracketPosition - 2);

        return [
            'text' => '[' . self::processTextPart($linkText) . '](' . self::escapeLinkUrl($linkUrl) . ')',
            'end' => $closingParenthesisPosition + 1,
        ];
    }

    private static function findClosingLinkBracket(string $text, int $offset): ?int
    {
        $length = self::length($text);
        $nestedBrackets = 0;

        for ($index = $offset; $index < $length; $index++) {
            if (self::isEscapedAt($text, $index)) {
                continue;
            }

            $character = self::charAt($text, $index);

            if ($character === '[') {
                $nestedBrackets++;
                continue;
            }

            if ($character !== ']') {
                continue;
            }

            if ($nestedBrackets === 0) {
                return $index;
            }

            $nestedBrackets--;
        }

        return null;
    }

    private static function findClosingLinkParenthesis(string $text, int $offset): ?int
    {
        $length = self::length($text);
        $nestedParentheses = 0;

        for ($index = $offset; $index < $length; $index++) {
            if (self::isEscapedAt($text, $index)) {
                continue;
            }

            $character = self::charAt($text, $index);

            if ($character === '(') {
                $nestedParentheses++;
                continue;
            }

            if ($character !== ')') {
                continue;
            }

            if ($nestedParentheses === 0) {
                return $index;
            }

            $nestedParentheses--;
        }

        return null;
    }

    /** @return array{text: string, end: int}|null */
    private static function parseFormattingEntity(string $text, int $openingPosition): ?array
    {
        foreach (self::FORMATTING_MARKERS as $sourceMarker => $telegramMarker) {
            if (!self::startsWithAt($text, $sourceMarker, $openingPosition)) {
                continue;
            }

            $sourceMarkerLength = self::length($sourceMarker);

            if (!self::canOpenFormattingEntity($text, $openingPosition, $sourceMarker)) {
                continue;
            }

            $closingPosition = self::findClosingFormattingMarker($text, $sourceMarker, $openingPosition + $sourceMarkerLength);

            if ($closingPosition === null) {
                continue;
            }

            $content = self::slice($text, $openingPosition + $sourceMarkerLength, $closingPosition - $openingPosition - $sourceMarkerLength);

            return [
                'text' => $telegramMarker . self::processTextPart($content) . $telegramMarker,
                'end' => $closingPosition + $sourceMarkerLength,
            ];
        }

        return null;
    }

    private static function findClosingFormattingMarker(string $text, string $sourceMarker, int $offset): ?int
    {
        $lineEndPosition = self::position($text, "\n", $offset);
        $searchLimit = $lineEndPosition ?? self::length($text);
        $searchOffset = $offset;

        while (true) {
            $closingPosition = self::findUnescaped($text, $sourceMarker, $searchOffset);

            if ($closingPosition === null || $closingPosition >= $searchLimit) {
                return null;
            }

            $content = self::slice($text, $offset, $closingPosition - $offset);

            if (self::hasNonWhitespace($content) && self::canCloseFormattingEntity($text, $closingPosition, $sourceMarker)) {
                return $closingPosition;
            }

            $searchOffset = $closingPosition + 1;
        }
    }

    private static function canOpenFormattingEntity(string $text, int $openingPosition, string $sourceMarker): bool
    {
        $sourceMarkerLength = self::length($sourceMarker);
        $previousCharacter = self::charAt($text, $openingPosition - 1);
        $nextCharacter = self::charAt($text, $openingPosition + $sourceMarkerLength);

        if ($nextCharacter === '' || self::isWhitespace($nextCharacter)) {
            return false;
        }

        if ($sourceMarkerLength === 1 && ($previousCharacter === $sourceMarker || $nextCharacter === $sourceMarker)) {
            return false;
        }

        if ($sourceMarker === '_' && self::isAsciiAlphanumeric($previousCharacter) && self::isAsciiAlphanumeric($nextCharacter)) {
            return false;
        }

        return true;
    }

    private static function canCloseFormattingEntity(string $text, int $closingPosition, string $sourceMarker): bool
    {
        $sourceMarkerLength = self::length($sourceMarker);
        $previousCharacter = self::charAt($text, $closingPosition - 1);

        if ($previousCharacter === '' || self::isWhitespace($previousCharacter)) {
            return false;
        }

        if ($sourceMarkerLength === 1 && $previousCharacter === $sourceMarker) {
            return false;
        }

        return true;
    }

    private static function processBlockquoteLine(string $line): string
    {
        $length = self::length($line);
        $markerLength = 0;

        while ($markerLength < $length && self::charAt($line, $markerLength) === '>') {
            $markerLength++;
        }

        return self::slice($line, 0, $markerLength) . self::processTextPart(self::slice($line, $markerLength));
    }

    private static function escapeMarkdownText(string $text): string
    {
        $result = '';
        $length = self::length($text);

        for ($index = 0; $index < $length; $index++) {
            $character = self::charAt($text, $index);

            if ($character === '\\') {
                $nextCharacter = self::charAt($text, $index + 1);

                if ($nextCharacter !== '' && self::isAlreadyEscapedCharacter($nextCharacter)) {
                    $result .= '\\' . $nextCharacter;
                    $index++;
                    continue;
                }

                $result .= '\\\\';
                continue;
            }

            if (self::isReservedCharacter($character)) {
                $result .= '\\' . $character;
                continue;
            }

            $result .= $character;
        }

        return $result;
    }

    private static function escapeCodeContent(string $text): string
    {
        $result = '';
        $length = self::length($text);

        for ($index = 0; $index < $length; $index++) {
            $character = self::charAt($text, $index);

            if ($character === '\\') {
                $nextCharacter = self::charAt($text, $index + 1);

                if ($nextCharacter === '\\' || $nextCharacter === '`') {
                    $result .= '\\' . $nextCharacter;
                    $index++;
                    continue;
                }

                $result .= '\\\\';
                continue;
            }

            if ($character === '`') {
                $result .= '\\`';
                continue;
            }

            $result .= $character;
        }

        return $result;
    }

    private static function escapeLinkUrl(string $url): string
    {
        $result = '';
        $length = self::length($url);

        for ($index = 0; $index < $length; $index++) {
            $character = self::charAt($url, $index);

            if ($character === '\\') {
                $nextCharacter = self::charAt($url, $index + 1);

                if ($nextCharacter !== '' && self::isAlreadyEscapedCharacter($nextCharacter)) {
                    $result .= '\\' . $nextCharacter;
                    $index++;
                    continue;
                }

                $result .= '\\\\';
                continue;
            }

            if ($character === ')') {
                $result .= '\\)';
                continue;
            }

            $result .= $character;
        }

        return $result;
    }

    private static function findUnescaped(string $text, string $needle, int $offset): ?int
    {
        $position = self::position($text, $needle, $offset);

        while ($position !== null) {
            if (!self::isEscapedAt($text, $position)) {
                return $position;
            }

            $position = self::position($text, $needle, $position + 1);
        }

        return null;
    }

    private static function startsWithAt(string $text, string $needle, int $position): bool
    {
        return self::slice($text, $position, self::length($needle)) === $needle;
    }

    private static function isBlockquoteStart(string $text, int $position): bool
    {
        return self::charAt($text, $position) === '>' && ($position === 0 || self::charAt($text, $position - 1) === "\n");
    }

    private static function isEscapedAt(string $text, int $position): bool
    {
        $backslashCount = 0;

        for ($index = $position - 1; $index >= 0 && self::charAt($text, $index) === '\\'; $index--) {
            $backslashCount++;
        }

        return $backslashCount % 2 === 1;
    }

    private static function isReservedCharacter(string $character): bool
    {
        return self::position(self::RESERVED_CHARACTERS, $character, 0) !== null;
    }

    private static function isAlreadyEscapedCharacter(string $character): bool
    {
        if ($character === '') {
            return false;
        }

        $unicodeCodePoint = mb_ord($character, self::ENCODING);

        if ($unicodeCodePoint === false || $unicodeCodePoint < 33 || $unicodeCodePoint > 126) {
            return false;
        }

        $isDigit = $unicodeCodePoint >= 48 && $unicodeCodePoint <= 57;
        $isUppercaseLetter = $unicodeCodePoint >= 65 && $unicodeCodePoint <= 90;
        $isLowercaseLetter = $unicodeCodePoint >= 97 && $unicodeCodePoint <= 122;

        return !$isDigit && !$isUppercaseLetter && !$isLowercaseLetter;
    }

    private static function isAsciiAlphanumeric(string $character): bool
    {
        if ($character === '') {
            return false;
        }

        $unicodeCodePoint = mb_ord($character, self::ENCODING);

        if ($unicodeCodePoint === false) {
            return false;
        }

        return ($unicodeCodePoint >= 48 && $unicodeCodePoint <= 57)
            || ($unicodeCodePoint >= 65 && $unicodeCodePoint <= 90)
            || ($unicodeCodePoint >= 97 && $unicodeCodePoint <= 122);
    }

    private static function isWhitespace(string $character): bool
    {
        return $character !== '' && preg_match('/^\s$/u', $character) === 1;
    }

    private static function hasNonWhitespace(string $text): bool
    {
        return preg_match('/\S/u', $text) === 1;
    }

    private static function normalizeCommonMarkCodeSpanContent(string $content): string
    {
        if (!self::hasNonWhitespace($content)) {
            return $content;
        }

        if (self::charAt($content, 0) !== ' ' || self::charAt($content, self::length($content) - 1) !== ' ') {
            return $content;
        }

        return self::slice($content, 1, self::length($content) - 2);
    }

    private static function countRepeatedCharacter(string $text, int $position, string $character): int
    {
        $count = 0;
        $length = self::length($text);

        for ($index = $position; $index < $length && self::charAt($text, $index) === $character; $index++) {
            $count++;
        }

        return $count;
    }

    private static function length(string $text): int
    {
        return mb_strlen($text, self::ENCODING);
    }

    private static function slice(string $text, int $start, ?int $length = null): string
    {
        return mb_substr($text, $start, $length, self::ENCODING);
    }

    private static function position(string $text, string $needle, int $offset): ?int
    {
        $position = mb_strpos($text, $needle, $offset, self::ENCODING);

        return $position === false ? null : $position;
    }

    private static function charAt(string $text, int $position): string
    {
        if ($position < 0 || $position >= self::length($text)) {
            return '';
        }

        return self::slice($text, $position, 1);
    }
    /** @return array{text: string, end: int}|null */
    private static function parseLatexFormula(string $text, int $openingPosition): ?array
    {
        if (self::startsWithAt($text, '$$', $openingPosition)) {
            return self::parseDelimitedLatexFormula($text, $openingPosition, '$$', '$$', true);
        }

        if (self::charAt($text, $openingPosition) === '$') {
            if (!self::canOpenInlineDollarFormula($text, $openingPosition)) {
                return null;
            }

            return self::parseDelimitedLatexFormula($text, $openingPosition, '$', '$', false);
        }

        if (self::startsWithAt($text, '\\(', $openingPosition)) {
            return self::parseDelimitedLatexFormula($text, $openingPosition, '\\(', '\\)', false);
        }

        if (self::startsWithAt($text, '\\[', $openingPosition)) {
            return self::parseDelimitedLatexFormula($text, $openingPosition, '\\[', '\\]', true);
        }

        if (self::startsWithAt($text, '\\begin{', $openingPosition)) {
            return self::parseLatexEnvironmentFormula($text, $openingPosition);
        }

        return null;
    }

    /** @return array{text: string, end: int}|null */
    private static function parseDelimitedLatexFormula(string $text, int $openingPosition, string $openingMarker, string $closingMarker, bool $isDisplayFormula): ?array
    {
        $openingMarkerLength = self::length($openingMarker);
        $searchOffset = $openingPosition + $openingMarkerLength;
        $lineEndPosition = $isDisplayFormula ? null : self::position($text, "\n", $searchOffset);
        $searchLimit = $lineEndPosition ?? self::length($text);

        while (true) {
            $closingPosition = self::findUnescaped($text, $closingMarker, $searchOffset);

            if ($closingPosition === null || $closingPosition >= $searchLimit) {
                return null;
            }

            if ($closingMarker === '$' && !self::canCloseInlineDollarFormula($text, $closingPosition)) {
                $searchOffset = $closingPosition + 1;
                continue;
            }

            $content = self::slice($text, $openingPosition + $openingMarkerLength, $closingPosition - $openingPosition - $openingMarkerLength);

            if (!self::hasNonWhitespace($content)) {
                return null;
            }

            return [
                'text' => self::formatLatexFormulaReplacement($content, $isDisplayFormula),
                'end' => $closingPosition + self::length($closingMarker),
            ];
        }
    }

    /** @return array{text: string, end: int}|null */
    private static function parseLatexEnvironmentFormula(string $text, int $openingPosition): ?array
    {
        $environment = self::readLatexEnvironmentNameAt($text, $openingPosition);

        if ($environment === null || !self::isLatexFormulaEnvironment($environment['name'])) {
            return null;
        }

        $closingMarker = '\\end{' . $environment['name'] . '}';
        $closingPosition = self::findUnescaped($text, $closingMarker, $environment['end']);

        if ($closingPosition === null) {
            return null;
        }

        $contentStart = $environment['end'];
        $normalizedEnvironmentName = self::normalizeLatexEnvironmentName($environment['name']);

        if ($normalizedEnvironmentName === 'array') {
            $arrayColumns = self::parseLatexArgument($text, $contentStart);

            if ($arrayColumns !== null) {
                $contentStart = $arrayColumns['end'];
            }
        }

        $content = self::slice($text, $contentStart, $closingPosition - $contentStart);

        if (!self::hasNonWhitespace($content)) {
            return null;
        }

        $formula = self::latexEnvironmentBracket($environment['name'], true) . $content . self::latexEnvironmentBracket($environment['name'], false);

        return [
            'text' => self::formatLatexFormulaReplacement($formula, true),
            'end' => $closingPosition + self::length($closingMarker),
        ];
    }

    private static function formatLatexFormulaReplacement(string $formula, bool $isDisplayFormula): string
    {
        $replacement = self::latexToTelegramText($formula);

        if ($replacement === '') {
            return '';
        }

        $escapedReplacement = self::escapeMarkdownText($replacement);

        return $isDisplayFormula ? "\n" . $escapedReplacement . "\n" : $escapedReplacement;
    }

    private static function latexToTelegramText(string $formula): string
    {
        $formula = str_replace(["\r\n", "\r"], "\n", $formula);

        return self::cleanupLatexFormulaText(self::convertLatexFragment($formula));
    }

    private static function convertLatexFragment(string $formula): string
    {
        $result = '';
        $index = 0;
        $length = self::length($formula);

        while ($index < $length) {
            $character = self::charAt($formula, $index);

            if ($character === '\\') {
                $command = self::readLatexCommand($formula, $index);

                if ($command === null) {
                    $result .= '\\';
                    $index++;
                    continue;
                }

                $commandName = $command['name'];
                $commandEnd = $command['end'];

                if ($commandName === '\\') {
                    $result .= "\n";
                    $index = $commandEnd;
                    continue;
                }

                if (self::isLatexFractionCommand($commandName)) {
                    $numerator = self::parseLatexArgument($formula, $commandEnd);

                    if ($numerator === null) {
                        $result .= '⁄';
                        $index = $commandEnd;
                        continue;
                    }

                    $denominator = self::parseLatexArgument($formula, $numerator['end']);

                    if ($denominator === null) {
                        $result .= self::latexToTelegramText($numerator['content']) . '⁄';
                        $index = $numerator['end'];
                        continue;
                    }

                    $result .= self::formatLatexFraction(
                        self::latexToTelegramText($numerator['content']),
                        self::latexToTelegramText($denominator['content'])
                    );
                    $index = $denominator['end'];
                    continue;
                }

                if ($commandName === 'sqrt') {
                    $rootIndex = null;
                    $argumentOffset = $commandEnd;
                    $optionalRootIndex = self::parseLatexOptionalBracketArgument($formula, $argumentOffset);

                    if ($optionalRootIndex !== null) {
                        $rootIndex = self::latexToTelegramText($optionalRootIndex['content']);
                        $argumentOffset = $optionalRootIndex['end'];
                    }

                    $radicand = self::parseLatexArgument($formula, $argumentOffset);

                    if ($radicand === null) {
                        $result .= '√';
                        $index = $argumentOffset;
                        continue;
                    }

                    $result .= self::formatLatexRoot(self::latexToTelegramText($radicand['content']), $rootIndex);
                    $index = $radicand['end'];
                    continue;
                }

                if ($commandName === 'binom') {
                    $top = self::parseLatexArgument($formula, $commandEnd);
                    $bottom = $top === null ? null : self::parseLatexArgument($formula, $top['end']);

                    if ($top !== null && $bottom !== null) {
                        $result .= '(' . self::latexToTelegramText($top['content']) . ' choose ' . self::latexToTelegramText($bottom['content']) . ')';
                        $index = $bottom['end'];
                        continue;
                    }
                }

                if ($commandName === 'overset' || $commandName === 'stackrel' || $commandName === 'underset') {
                    $script = self::parseLatexArgument($formula, $commandEnd);
                    $base = $script === null ? null : self::parseLatexArgument($formula, $script['end']);

                    if ($script !== null && $base !== null) {
                        $convertedBase = self::latexToTelegramText($base['content']);
                        $convertedScript = self::latexToTelegramText($script['content']);
                        $result .= $commandName === 'underset'
                            ? $convertedBase . self::toSubscriptText($convertedScript)
                            : $convertedBase . self::toSuperscriptText($convertedScript);
                        $index = $base['end'];
                        continue;
                    }
                }

                if ($commandName === 'begin' || $commandName === 'end') {
                    $environment = self::parseLatexArgument($formula, $commandEnd);

                    if ($environment === null) {
                        $index = $commandEnd;
                        continue;
                    }

                    $normalizedEnvironmentName = self::normalizeLatexEnvironmentName($environment['content']);
                    $result .= self::latexEnvironmentBracket($environment['content'], $commandName === 'begin');
                    $index = $environment['end'];

                    if ($commandName === 'begin' && $normalizedEnvironmentName === 'array') {
                        $arrayColumns = self::parseLatexArgument($formula, $index);

                        if ($arrayColumns !== null) {
                            $index = $arrayColumns['end'];
                        }
                    }

                    continue;
                }

                if (isset(self::LATEX_ACCENTS[$commandName])) {
                    $argument = self::parseLatexArgument($formula, $commandEnd);

                    if ($argument === null) {
                        $index = $commandEnd;
                        continue;
                    }

                    $result .= self::applyCombiningMark(self::latexToTelegramText($argument['content']), self::LATEX_ACCENTS[$commandName]);
                    $index = $argument['end'];
                    continue;
                }

                if (self::isLatexStyleCommand($commandName)) {
                    $argument = self::parseLatexArgument($formula, $commandEnd);

                    if ($argument === null) {
                        $index = $commandEnd;
                        continue;
                    }

                    $result .= self::applyLatexStyle($commandName, self::latexToTelegramText($argument['content']));
                    $index = $argument['end'];
                    continue;
                }

                if (self::isLatexTextCommand($commandName)) {
                    $argument = self::parseLatexArgument($formula, $commandEnd);

                    if ($argument === null) {
                        $index = $commandEnd;
                        continue;
                    }

                    $result .= self::convertLatexFragment($argument['content']);
                    $index = $argument['end'];
                    continue;
                }

                if ($commandName === 'label') {
                    $argument = self::parseLatexArgument($formula, $commandEnd);
                    $index = $argument['end'] ?? $commandEnd;
                    continue;
                }

                if ($commandName === 'tag') {
                    $argument = self::parseLatexArgument($formula, $commandEnd);

                    if ($argument !== null) {
                        $result .= '(' . self::latexToTelegramText($argument['content']) . ')';
                        $index = $argument['end'];
                        continue;
                    }
                }

                if (self::isLatexIgnoredArgumentCommand($commandName)) {
                    $argument = self::parseLatexArgument($formula, $commandEnd);
                    $index = $argument['end'] ?? $commandEnd;
                    continue;
                }

                if (self::isLatexDelimiterSizingCommand($commandName)) {
                    $index = $commandEnd;

                    if (self::charAt($formula, $index) === '.') {
                        $index++;
                    }

                    continue;
                }

                if (self::isLatexIgnoredCommand($commandName)) {
                    $index = $commandEnd;
                    continue;
                }

                if (isset(self::LATEX_COMMAND_REPLACEMENTS[$commandName])) {
                    $result .= self::LATEX_COMMAND_REPLACEMENTS[$commandName];
                    $index = $commandEnd;
                    continue;
                }

                $result .= $commandName;
                $index = $commandEnd;
                continue;
            }

            if ($character === '^' || $character === '_') {
                $argument = self::parseLatexArgument($formula, $index + 1);

                if ($argument === null) {
                    $result .= $character;
                    $index++;
                    continue;
                }

                $convertedArgument = self::latexToTelegramText($argument['content']);
                $result .= $character === '^'
                    ? self::toSuperscriptText($convertedArgument)
                    : self::toSubscriptText($convertedArgument);
                $index = $argument['end'];
                continue;
            }

            if ($character === '{') {
                $group = self::parseLatexGroup($formula, $index);

                if ($group !== null) {
                    $result .= self::convertLatexFragment($group['content']);
                    $index = $group['end'];
                    continue;
                }
            }

            if ($character === '}') {
                $index++;
                continue;
            }

            if ($character === '&' || $character === '~') {
                $result .= ' ';
                $index++;
                continue;
            }

            $result .= $character;
            $index++;
        }

        return $result;
    }

    /** @return array{name: string, end: int}|null */
    private static function readLatexCommand(string $text, int $position): ?array
    {
        if (self::charAt($text, $position) !== '\\') {
            return null;
        }

        $nextCharacter = self::charAt($text, $position + 1);

        if ($nextCharacter === '') {
            return null;
        }

        if (!self::isAsciiLetter($nextCharacter)) {
            return [
                'name' => $nextCharacter,
                'end' => $position + 2,
            ];
        }

        $index = $position + 1;
        $length = self::length($text);

        while ($index < $length && self::isAsciiLetter(self::charAt($text, $index))) {
            $index++;
        }

        $name = self::slice($text, $position + 1, $index - $position - 1);

        if (self::charAt($text, $index) === '*') {
            $index++;
        }

        return [
            'name' => $name,
            'end' => $index,
        ];
    }

    /** @return array{name: string, end: int}|null */
    private static function readLatexEnvironmentNameAt(string $text, int $position): ?array
    {
        $prefix = '\\begin{';

        if (!self::startsWithAt($text, $prefix, $position)) {
            return null;
        }

        $nameStart = $position + self::length($prefix);
        $nameEnd = self::position($text, '}', $nameStart);

        if ($nameEnd === null) {
            return null;
        }

        return [
            'name' => self::slice($text, $nameStart, $nameEnd - $nameStart),
            'end' => $nameEnd + 1,
        ];
    }

    /** @return array{content: string, end: int}|null */
    private static function parseLatexArgument(string $text, int $offset): ?array
    {
        $offset = self::skipLatexWhitespace($text, $offset);
        $character = self::charAt($text, $offset);

        if ($character === '') {
            return null;
        }

        if ($character === '{') {
            return self::parseLatexGroup($text, $offset);
        }

        if ($character === '\\') {
            $command = self::readLatexCommand($text, $offset);

            if ($command !== null) {
                return [
                    'content' => self::slice($text, $offset, $command['end'] - $offset),
                    'end' => $command['end'],
                ];
            }
        }

        return [
            'content' => $character,
            'end' => $offset + 1,
        ];
    }

    /** @return array{content: string, end: int}|null */
    private static function parseLatexGroup(string $text, int $position): ?array
    {
        if (self::charAt($text, $position) !== '{') {
            return null;
        }

        $depth = 1;
        $index = $position + 1;
        $length = self::length($text);

        while ($index < $length) {
            $character = self::charAt($text, $index);

            if ($character === '\\' && (self::charAt($text, $index + 1) === '{' || self::charAt($text, $index + 1) === '}')) {
                $index += 2;
                continue;
            }

            if ($character === '{') {
                $depth++;
            } elseif ($character === '}') {
                $depth--;

                if ($depth === 0) {
                    return [
                        'content' => self::slice($text, $position + 1, $index - $position - 1),
                        'end' => $index + 1,
                    ];
                }
            }

            $index++;
        }

        return null;
    }

    /** @return array{content: string, end: int}|null */
    private static function parseLatexOptionalBracketArgument(string $text, int $offset): ?array
    {
        $offset = self::skipLatexWhitespace($text, $offset);

        if (self::charAt($text, $offset) !== '[') {
            return null;
        }

        $depth = 1;
        $index = $offset + 1;
        $length = self::length($text);

        while ($index < $length) {
            $character = self::charAt($text, $index);

            if ($character === '\\') {
                $index += 2;
                continue;
            }

            if ($character === '[') {
                $depth++;
            } elseif ($character === ']') {
                $depth--;

                if ($depth === 0) {
                    return [
                        'content' => self::slice($text, $offset + 1, $index - $offset - 1),
                        'end' => $index + 1,
                    ];
                }
            }

            $index++;
        }

        return null;
    }

    private static function formatLatexFraction(string $numerator, string $denominator): string
    {
        $vulgarFractionKey = $numerator . '/' . $denominator;

        if (isset(self::LATEX_VULGAR_FRACTIONS[$vulgarFractionKey])) {
            return self::LATEX_VULGAR_FRACTIONS[$vulgarFractionKey];
        }

        if (self::isShortLatexAtom($numerator) && self::isShortLatexAtom($denominator)) {
            return $numerator . '⁄' . $denominator;
        }

        return '(' . $numerator . ')⁄(' . $denominator . ')';
    }

    private static function formatLatexRoot(string $radicand, ?string $rootIndex): string
    {
        $prefix = $rootIndex === null || $rootIndex === '' ? '' : self::toSuperscriptText($rootIndex);

        if (self::isShortLatexAtom($radicand)) {
            return $prefix . '√' . $radicand;
        }

        return $prefix . '√(' . $radicand . ')';
    }

    private static function toSuperscriptText(string $text): string
    {
        return self::convertScriptText($text, self::LATEX_SUPERSCRIPT_CHARACTERS, '^');
    }

    private static function toSubscriptText(string $text): string
    {
        return self::convertScriptText($text, self::LATEX_SUBSCRIPT_CHARACTERS, '_');
    }

    /** @param array<string, string> $characterMap */
    private static function convertScriptText(string $text, array $characterMap, string $fallbackMarker): string
    {
        $result = '';
        $length = self::length($text);

        for ($index = 0; $index < $length; $index++) {
            $character = self::charAt($text, $index);

            if (self::isWhitespace($character)) {
                continue;
            }

            if (!isset($characterMap[$character])) {
                return self::length($text) === 1 ? $fallbackMarker . $text : $fallbackMarker . '(' . $text . ')';
            }

            $result .= $characterMap[$character];
        }

        return $result;
    }

    private static function applyCombiningMark(string $text, string $combiningMark): string
    {
        $result = '';
        $length = self::length($text);

        for ($index = 0; $index < $length; $index++) {
            $character = self::charAt($text, $index);
            $result .= $character;

            if ($character !== "\n" && !self::isWhitespace($character)) {
                $result .= $combiningMark;
            }
        }

        return $result;
    }

    private static function applyLatexStyle(string $style, string $text): string
    {
        if (!isset(self::LATEX_STYLED_CHARACTERS[$style])) {
            return $text;
        }

        $result = '';
        $length = self::length($text);

        for ($index = 0; $index < $length; $index++) {
            $character = self::charAt($text, $index);
            $result .= self::LATEX_STYLED_CHARACTERS[$style][$character] ?? $character;
        }

        return $result;
    }

    private static function isLatexFormulaEnvironment(string $environmentName): bool
    {
        return in_array(self::normalizeLatexEnvironmentName($environmentName), [
            'equation',
            'align',
            'aligned',
            'gather',
            'gathered',
            'multline',
            'split',
            'cases',
            'matrix',
            'pmatrix',
            'bmatrix',
            'Bmatrix',
            'vmatrix',
            'Vmatrix',
            'smallmatrix',
            'array',
        ], true);
    }

    private static function normalizeLatexEnvironmentName(string $environmentName): string
    {
        if ($environmentName !== '' && self::charAt($environmentName, self::length($environmentName) - 1) === '*') {
            return self::slice($environmentName, 0, self::length($environmentName) - 1);
        }

        return $environmentName;
    }

    private static function latexEnvironmentBracket(string $environmentName, bool $isOpening): string
    {
        switch (self::normalizeLatexEnvironmentName($environmentName)) {
            case 'pmatrix':
            case 'smallmatrix':
                return $isOpening ? '(' : ')';
            case 'bmatrix':
                return $isOpening ? '[' : ']';
            case 'Bmatrix':
                return $isOpening ? '{' : '}';
            case 'vmatrix':
                return '|';
            case 'Vmatrix':
                return '‖';
            case 'cases':
                return $isOpening ? '{' : '';
            default:
                return '';
        }
    }

    private static function isLatexFractionCommand(string $commandName): bool
    {
        return in_array($commandName, ['frac', 'dfrac', 'tfrac', 'cfrac'], true);
    }

    private static function isLatexStyleCommand(string $commandName): bool
    {
        return in_array($commandName, [
            'mathbb',
            'mathcal',
            'mathscr',
            'mathfrak',
            'mathbf',
            'boldsymbol',
            'bm',
            'mathit',
            'mathrm',
            'mathsf',
            'mathtt',
        ], true);
    }

    private static function isLatexTextCommand(string $commandName): bool
    {
        return in_array($commandName, [
            'text',
            'textrm',
            'textit',
            'textbf',
            'mbox',
            'operatorname',
            'boxed',
            'fbox',
            'cancel',
            'overbrace',
            'underbrace',
        ], true);
    }

    private static function isLatexDelimiterSizingCommand(string $commandName): bool
    {
        return in_array($commandName, [
            'left',
            'right',
            'middle',
            'big',
            'Big',
            'bigg',
            'Bigg',
            'bigl',
            'bigr',
            'Bigl',
            'Bigr',
            'biggl',
            'biggr',
            'Biggl',
            'Biggr',
        ], true);
    }

    private static function isLatexIgnoredCommand(string $commandName): bool
    {
        return in_array($commandName, [
            'displaystyle',
            'textstyle',
            'scriptstyle',
            'scriptscriptstyle',
            'limits',
            'nolimits',
            'nonumber',
            'notag',
        ], true);
    }

    private static function isLatexIgnoredArgumentCommand(string $commandName): bool
    {
        return in_array($commandName, ['phantom', 'hphantom', 'vphantom'], true);
    }

    private static function isShortLatexAtom(string $text): bool
    {
        if ($text === '' || self::length($text) > 4 || preg_match('/\s/u', $text) === 1) {
            return false;
        }

        return preg_match('/^[^+\-=<>≤≥≈≠∑∏∫⁄]+$/u', $text) === 1;
    }

    private static function canOpenInlineDollarFormula(string $text, int $position): bool
    {
        if (self::isEscapedAt($text, $position)) {
            return false;
        }

        $previousCharacter = self::charAt($text, $position - 1);

        if ($previousCharacter !== '' && self::isAsciiAlphanumeric($previousCharacter)) {
            return false;
        }

        $nextPosition = $position + 1;
        $nextCharacter = self::charAt($text, $nextPosition);

        while ($nextCharacter !== '' && self::isWhitespace($nextCharacter)) {
            if ($nextCharacter === "\n") {
                return false;
            }

            $nextPosition++;
            $nextCharacter = self::charAt($text, $nextPosition);
        }

        return $nextCharacter !== '' && !self::isAsciiDigit($nextCharacter) && $nextCharacter !== '$';
    }

    private static function canCloseInlineDollarFormula(string $text, int $position): bool
    {
        $previousPosition = $position - 1;
        $previousCharacter = self::charAt($text, $previousPosition);

        while ($previousCharacter !== '' && self::isWhitespace($previousCharacter)) {
            if ($previousCharacter === "\n") {
                return false;
            }

            $previousPosition--;
            $previousCharacter = self::charAt($text, $previousPosition);
        }

        if ($previousCharacter === '' || $previousCharacter === '$') {
            return false;
        }

        $nextCharacter = self::charAt($text, $position + 1);

        return $nextCharacter === '' || !self::isAsciiAlphanumeric($nextCharacter);
    }

    private static function skipLatexWhitespace(string $text, int $offset): int
    {
        $length = self::length($text);

        while ($offset < $length && self::isWhitespace(self::charAt($text, $offset))) {
            $offset++;
        }

        return $offset;
    }

    private static function cleanupLatexFormulaText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private static function isAsciiDigit(string $character): bool
    {
        if ($character === '') {
            return false;
        }

        $unicodeCodePoint = mb_ord($character, self::ENCODING);

        return $unicodeCodePoint !== false && $unicodeCodePoint >= 48 && $unicodeCodePoint <= 57;
    }

    private static function isAsciiLetter(string $character): bool
    {
        if ($character === '') {
            return false;
        }

        $unicodeCodePoint = mb_ord($character, self::ENCODING);

        if ($unicodeCodePoint === false) {
            return false;
        }

        return ($unicodeCodePoint >= 65 && $unicodeCodePoint <= 90)
            || ($unicodeCodePoint >= 97 && $unicodeCodePoint <= 122);
    }
}
