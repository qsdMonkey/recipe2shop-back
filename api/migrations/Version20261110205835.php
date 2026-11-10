<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261110205835 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recettes favorites : favorite_recipe (unique user + recette, compteur d\'acceptations)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE favorite_recipe (id INT AUTO_INCREMENT NOT NULL, times_accepted INT NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, recipe_id INT NOT NULL, UNIQUE INDEX uniq_favorite_recipe (user_id, recipe_id), INDEX IDX_E63DDDCDA76ED395 (user_id), INDEX IDX_E63DDDCD59D8A214 (recipe_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE favorite_recipe ADD CONSTRAINT FK_E63DDDCDA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE favorite_recipe ADD CONSTRAINT FK_E63DDDCD59D8A214 FOREIGN KEY (recipe_id) REFERENCES recipe (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE favorite_recipe DROP FOREIGN KEY FK_E63DDDCDA76ED395');
        $this->addSql('ALTER TABLE favorite_recipe DROP FOREIGN KEY FK_E63DDDCD59D8A214');
        $this->addSql('DROP TABLE favorite_recipe');
    }
}
