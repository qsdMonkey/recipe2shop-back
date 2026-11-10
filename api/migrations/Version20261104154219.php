<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261104154219 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Étape 5 — Recettes : recipe, recipe_ingredient, session_recipe, inventory_item, repas prévus et relecture mémoire sur shopping_session';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE inventory_item (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(100) NOT NULL, quantity DOUBLE PRECISION DEFAULT NULL, unit VARCHAR(10) DEFAULT NULL, session_id INT NOT NULL, INDEX IDX_55BDEA30613FECDF (session_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE recipe (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(150) NOT NULL, description LONGTEXT DEFAULT NULL, servings INT NOT NULL, meals_covered INT NOT NULL, meal_tags JSON NOT NULL, prep_time_min INT NOT NULL, cook_time_min INT NOT NULL, difficulty VARCHAR(10) NOT NULL, uses_inventory JSON NOT NULL, steps JSON NOT NULL, equipment JSON NOT NULL, storage_tip LONGTEXT DEFAULT NULL, prompt_version VARCHAR(50) NOT NULL, created_at DATETIME NOT NULL, created_by_id INT DEFAULT NULL, INDEX IDX_DA88B137B03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE recipe_ingredient (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, name VARCHAR(100) NOT NULL, quantity DOUBLE PRECISION DEFAULT NULL, unit VARCHAR(10) DEFAULT NULL, category VARCHAR(30) NOT NULL, optional TINYINT NOT NULL, recipe_id INT NOT NULL, INDEX IDX_22D1FE1359D8A214 (recipe_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE session_recipe (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(20) NOT NULL, servings INT NOT NULL, suggested_day INT DEFAULT NULL, position INT NOT NULL, cooked TINYINT NOT NULL, warnings JSON NOT NULL, comment VARCHAR(500) DEFAULT NULL, proposed_at DATETIME NOT NULL, decided_at DATETIME DEFAULT NULL, session_id INT NOT NULL, recipe_id INT NOT NULL, UNIQUE INDEX uniq_session_recipe (session_id, recipe_id), INDEX IDX_B5E3BB6E613FECDF (session_id), INDEX IDX_B5E3BB6E59D8A214 (recipe_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE inventory_item ADD CONSTRAINT FK_55BDEA30613FECDF FOREIGN KEY (session_id) REFERENCES shopping_session (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE recipe ADD CONSTRAINT FK_DA88B137B03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE recipe_ingredient ADD CONSTRAINT FK_22D1FE1359D8A214 FOREIGN KEY (recipe_id) REFERENCES recipe (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE session_recipe ADD CONSTRAINT FK_B5E3BB6E613FECDF FOREIGN KEY (session_id) REFERENCES shopping_session (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE session_recipe ADD CONSTRAINT FK_B5E3BB6E59D8A214 FOREIGN KEY (recipe_id) REFERENCES recipe (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE shopping_session ADD meals_planned INT DEFAULT NULL, ADD meal_plan JSON DEFAULT NULL, ADD memory_reviewed_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE inventory_item DROP FOREIGN KEY FK_55BDEA30613FECDF');
        $this->addSql('ALTER TABLE recipe DROP FOREIGN KEY FK_DA88B137B03A8386');
        $this->addSql('ALTER TABLE recipe_ingredient DROP FOREIGN KEY FK_22D1FE1359D8A214');
        $this->addSql('ALTER TABLE session_recipe DROP FOREIGN KEY FK_B5E3BB6E613FECDF');
        $this->addSql('ALTER TABLE session_recipe DROP FOREIGN KEY FK_B5E3BB6E59D8A214');
        $this->addSql('DROP TABLE inventory_item');
        $this->addSql('DROP TABLE recipe');
        $this->addSql('DROP TABLE recipe_ingredient');
        $this->addSql('DROP TABLE session_recipe');
        $this->addSql('ALTER TABLE shopping_session DROP meals_planned, DROP meal_plan, DROP memory_reviewed_at');
    }
}
