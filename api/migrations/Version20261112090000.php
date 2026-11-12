<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recettes : astuces (recipe.tips), [] pour les recettes existantes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipe ADD tips JSON DEFAULT NULL');
        $this->addSql("UPDATE recipe SET tips = JSON_ARRAY()");
        $this->addSql('ALTER TABLE recipe MODIFY tips JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipe DROP tips');
    }
}
