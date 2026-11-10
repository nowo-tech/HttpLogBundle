<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Fixtures\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A host application entity sharing the default EntityManager with the bundle's entry. */
#[ORM\Entity]
#[ORM\Table(name: 'host_article')]
class HostArticle
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $title,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }
}
