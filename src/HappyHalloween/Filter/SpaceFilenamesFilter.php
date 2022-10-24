<?php

declare(strict_types=1);

namespace HappyHalloween\Filter;

use Laminas\Filter\FilterChain;
use Laminas\Filter\Word\DashToSeparator;
use Laminas\Filter\Word\UnderscoreToSeparator;

class SpaceFilenamesFilter
{
    private string $baseURL;

    public function __construct(string $baseURL)
    {
        $this->baseURL = $baseURL;
    }

    public function filterFilenames(array $files): array
    {
        $items = [];

        foreach ($files['files'] as $file) {
            $filename = $file->filename;
            $items[] = [
                'name' => $this->getName($filename),
                'label' => $this->stripFileExtension($filename),
                'image' => $this->getImagePath($filename)
            ];
        }
        sort($items);

        return $items;
    }

    protected function getName(string $name): string
    {
        $filterChain = new FilterChain();
        $filterChain
            ->attach(new DashToSeparator())
            ->attach(new UnderscoreToSeparator("'"));

        return ucwords($filterChain->filter($this->stripFileExtension($name)));
    }

    protected function stripFileExtension(string $name): string
    {
        return basename($name,'.' . pathinfo($name)['extension']);
    }

    protected function getImagePath(string $filename): string
    {
        return sprintf('%s/%s', $this->baseURL, $filename);
    }
}
