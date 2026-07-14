<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\PersonalityCard\PersonalityCard;
use Perk11\Viktor89\PersonalityCard\PersonalityCardElement;
use Perk11\Viktor89\PersonalityCard\PersonalityCardRarity;
use Perk11\Viktor89\PersonalityCard\PersonalityCardRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PersonalityCardRenderer::class)]
class PersonalityCardRendererTest extends TestCase
{
    private function portraitBytes(int $r = 60, int $g = 70, int $b = 120): string
    {
        $im = imagecreatetruecolor(256, 256);
        imagefilledrectangle($im, 0, 0, 256, 256, imagecolorallocate($im, $r, $g, $b));
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        imagedestroy($im);

        return $png;
    }

    private function card(string $rarity = PersonalityCardRarity::MYTHIC): PersonalityCard
    {
        return new PersonalityCard(
            name: 'Тестер',
            archetype: 'Хаотичный Бард',
            stats: ['wit' => 8, 'chaos' => 10, 'wisdom' => 3, 'menace' => 7],
            element: PersonalityCardElement::AETHER,
            ability: 'Мемная Диверсия',
            abilityEffect: 'превращает любой тред в мемологему.',
            specialAbility: 'Цепная Реакция',
            specialAbilityEffect: 'один мем порождает каскад из десятка ответов.',
            weakness: 'серьёзные темы без подвоха ломают весь настрой и выбивают из колеи надолго.',
            portraitPrompt: 'a grinning jester',
            rarity: $rarity,
            power: 28,
            stars: PersonalityCardRarity::stars($rarity),
            cardNumber: '#0042',
        );
    }

    public function testRendersValidPngAtCardDimensions(): void
    {
        $png = (new PersonalityCardRenderer())->render($this->card(), $this->portraitBytes());

        $this->assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8));
        $info = getimagesizefromstring($png);
        $this->assertNotFalse($info);
        $this->assertSame(760, $info[0]);
        $this->assertSame(1120, $info[1]);
        $this->assertSame('image/png', $info['mime']);
    }

    public function testDifferentRaritiesProduceDifferentFrames(): void
    {
        $renderer = new PersonalityCardRenderer();
        $portrait = $this->portraitBytes();

        $mythic = $renderer->render($this->card(PersonalityCardRarity::MYTHIC), $portrait);
        $common = $renderer->render($this->card(PersonalityCardRarity::COMMON), $portrait);

        $this->assertNotSame(md5($mythic), md5($common), 'Common and mythic cards must differ visually');
    }

    public function testDegradesGracefullyOnUnparseablePortrait(): void
    {
        $png = (new PersonalityCardRenderer())->render($this->card(), 'not-a-real-image');

        $this->assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8));
        $info = getimagesizefromstring($png);
        $this->assertSame(760, $info[0]);
        $this->assertSame(1120, $info[1]);
    }

    public function testRendersDifferentPortraitAspectRatiosWithoutError(): void
    {
        $wide = imagecreatetruecolor(1024, 512);
        imagefilledrectangle($wide, 0, 0, 1024, 512, imagecolorallocate($wide, 10, 20, 30));
        ob_start();
        imagepng($wide);
        $widePng = (string) ob_get_clean();
        imagedestroy($wide);

        $tall = imagecreatetruecolor(512, 1024);
        imagefilledrectangle($tall, 0, 0, 512, 1024, imagecolorallocate($tall, 200, 100, 50));
        ob_start();
        imagepng($tall);
        $tallPng = (string) ob_get_clean();
        imagedestroy($tall);

        $renderer = new PersonalityCardRenderer();
        $this->assertNotEmpty($renderer->render($this->card(), $widePng));
        $this->assertNotEmpty($renderer->render($this->card(), $tallPng));
    }
}
