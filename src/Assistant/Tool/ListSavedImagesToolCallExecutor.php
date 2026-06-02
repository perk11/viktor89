<?php

namespace Perk11\Viktor89\Assistant\Tool;

use Perk11\Viktor89\ImageGeneration\ImageRepository;
use Perk11\Viktor89\ImageGeneration\SavedImage;

class ListSavedImagesToolCallExecutor implements ToolCallExecutorInterface
{
    public function __construct(
        private readonly ImageRepository $imageRepository,
    ) {
    }

    public function executeToolCall(array $arguments): array
    {
        foreach ($arguments as $key => $value) {
            throw new \InvalidArgumentException("Unsupported argument: $key");
        }

        /** @var SavedImage[] $images */
        $images = $this->imageRepository->findAllPublicImages();
        $result = [];
        foreach ($images as $image) {
            $result[] = $image->name;
        }

        return $result;
    }
}
