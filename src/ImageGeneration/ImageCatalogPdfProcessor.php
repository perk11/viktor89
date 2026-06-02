<?php

namespace Perk11\Viktor89\ImageGeneration;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;
use TCPDF;

class ImageCatalogPdfProcessor implements MessageChainProcessor
{
    private const int IMAGES_PER_PAGE = 64;
    private const int THUMBNAIL_SIZE = 180; // pixels

    public function __construct(
        private readonly ImageRepository $imageRepository,
    ) {
    }

    public function processMessageChain(
        MessageChain $messageChain,
        ProgressUpdateCallback $progressUpdateCallback
    ): ProcessingResult {
        $lastMessage = $messageChain->last();

        $images = $this->imageRepository->findAllPublicImages();
        if (empty($images)) {
            return new ProcessingResult(
                InternalMessage::asResponseTo($lastMessage, 'Нет сохранённых изображений'),
                true
            );
        }

        $progressUpdateCallback(static::class, 'Generating PDF catalog with ' . count($images) . ' images...');

        $pdf = new TCPDF('P', PDF_UNIT, 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Viktor89');
        $pdf->SetAuthor('Viktor89');
        $pdf->SetTitle('Saved Images Catalog');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetFont('freesans', '', 8);

        $totalPages = (int)ceil(count($images) / self::IMAGES_PER_PAGE);
        $columns = 8;
        $rows = 8;
        $cellWidth = 200 / $columns; // A4 portrait width in mm
        $cellHeight = $cellWidth; // square cells

        $imageIndex = 0;
        $tempThumbnails = [];
        while ($imageIndex < count($images)) {
            $pdf->AddPage();
            $currentY = 5;

            for ($row = 0; $row < $rows && $imageIndex < count($images); $row++) {
                $currentX = 5;
                for ($col = 0; $col < $columns && $imageIndex < count($images); $col++) {
                    $image = $images[$imageIndex];
                    $thumbPath = $this->drawImageCell($pdf, $image, $cellWidth, $cellHeight, $currentX, $currentY);
                    if ($thumbPath !== null) {
                        $tempThumbnails[] = $thumbPath;
                    }
                    $currentX += $cellWidth;
                    $imageIndex++;
                }
                $currentY += $cellHeight;
            }

            $progressUpdateCallback(
                static::class,
                "Page " . ($pdf->getPage()) . " of $totalPages generated"
            );
        }

        $tmpFilePath = tempnam(sys_get_temp_dir(), 'v89-catalog-') . '.pdf';
        $pdf->Output($tmpFilePath, 'F');

        // Cleanup temp thumbnails
        foreach ($tempThumbnails as $thumbPath) {
            @unlink($thumbPath);
        }

        $options = [
            'chat_id' => $lastMessage->chatId,
            'reply_parameters' => [
                'message_id' => $lastMessage->id,
            ],
        ];

        $encodedFile = Request::encodeFile($tmpFilePath);
        $options['document'] = $encodedFile;
        $options['caption'] = "📚 Image Catalog ({$imageIndex} images)";

        $progressUpdateCallback(static::class, "Sending PDF ({$imageIndex} images, " . round(filesize($tmpFilePath) / 1024 / 1024, 1) . " MB)");

        $sentMessageResult = Request::sendDocument($options);

        unlink($tmpFilePath);

        if ($sentMessageResult->isOk() && $sentMessageResult->getResult() instanceof Message) {
            // logged by caller if needed
        } else {
            echo "Failed to send PDF catalog: " . json_encode($sentMessageResult->getRawData()) . "\n";
        }

        return new ProcessingResult(null, true);
    }

    private function drawImageCell(
        TCPDF $pdf,
        SavedImage $image,
        float $cellWidth,
        float $cellHeight,
        float $x,
        float $y
    ): ?string {
        // Draw background cell
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Rect($x, $y, $cellWidth, $cellHeight, 'DF');

        // Load and resize image, maintaining proportions
        $thumbnailPath = $this->getThumbnail($image->name);
        if ($thumbnailPath !== null) {
            $imgMaxWidth = $cellWidth - 2;
            $imgMaxHeight = $cellHeight - 7;
            list($imgWidth, $imgHeight) = getimagesize($thumbnailPath);
            $aspectRatio = $imgWidth / $imgHeight;

            if ($imgMaxWidth / $imgMaxHeight > $aspectRatio) {
                $drawWidth = $imgMaxHeight * $aspectRatio;
                $drawHeight = $imgMaxHeight;
            } else {
                $drawWidth = $imgMaxWidth;
                $drawHeight = $imgMaxWidth / $aspectRatio;
            }

            $imgX = $x + 1 + ($imgMaxWidth - $drawWidth) / 2;
            $imgY = $y + 1 + ($imgMaxHeight - $drawHeight) / 2;

            $pdf->Image($thumbnailPath, $imgX, $imgY, $drawWidth, $drawHeight, 'JPG', '', '', false, 300, '', false, false, 0, false, false, false);
        }

        // Draw name label
        $name = $image->name;
        $maxLabelWidth = $cellWidth - 2;

        $pdf->SetXY($x + 1, $y + $cellHeight - 6);
        $pdf->SetTextColor(0, 0, 0);

        $pdf->Cell($maxLabelWidth, 5, $name, 0, 0, 'L', false);

        return $thumbnailPath;
    }

    private function getThumbnail(string $imageName): ?string
    {
        $image = @imagecreatefromstring($this->imageRepository->retrieve($imageName));
        if ($image === false) {
            throw new \Exception("Failed to load image: $imageName");
        }

        $originalWidth = imagesx($image);
        $originalHeight = imagesy($image);

        // Calculate new dimensions maintaining aspect ratio
        $thumbSize = self::THUMBNAIL_SIZE;
        if ($originalWidth > $originalHeight) {
            $newWidth = $thumbSize;
            $newHeight = (int)(($thumbSize / $originalWidth) * $originalHeight);
        } else {
            $newHeight = $thumbSize;
            $newWidth = (int)(($thumbSize / $originalHeight) * $originalWidth);
        }

        $thumbnail = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);

        $tmpPath = tempnam(sys_get_temp_dir(), 'v89-thumb-') . '.jpg';
        imagejpeg($thumbnail, $tmpPath, 80);

        imagedestroy($thumbnail);
        imagedestroy($image);

        // Register temp file for cleanup by TCPDF
        return $tmpPath;
    }
}
