<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261104161920 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Étape 6 — Listes de courses : ingredient, shopping_list, shopping_list_item, shopping_list_item_source, liens vers le référentiel, version des données de liste';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE ingredient (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, normalized_name VARCHAR(100) NOT NULL, category VARCHAR(30) NOT NULL, default_unit VARCHAR(10) DEFAULT NULL, shelf_life_days INT DEFAULT NULL, avg_weight_per_piece DOUBLE PRECISION DEFAULT NULL, basic TINYINT NOT NULL, to_review TINYINT NOT NULL, aliases JSON NOT NULL, UNIQUE INDEX uniq_ingredient_normalized (normalized_name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE shopping_list (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(10) NOT NULL, name VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL, generated_at DATETIME DEFAULT NULL, generated_from_version INT DEFAULT 0 NOT NULL, excluded_ingredient_ids JSON NOT NULL, session_id INT NOT NULL, INDEX IDX_3DC1A459613FECDF (session_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE shopping_list_item (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(100) NOT NULL, quantity DOUBLE PRECISION DEFAULT NULL, unit VARCHAR(10) DEFAULT NULL, category VARCHAR(30) NOT NULL, checked TINYINT NOT NULL, origin VARCHAR(10) NOT NULL, optional TINYINT NOT NULL, basic TINYINT NOT NULL, covered_by_inventory TINYINT NOT NULL, inventory_deducted JSON DEFAULT NULL, list_id INT NOT NULL, ingredient_id INT DEFAULT NULL, INDEX IDX_4FB1C2243DAE168B (list_id), INDEX IDX_4FB1C224933FE08C (ingredient_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE shopping_list_item_source (id INT AUTO_INCREMENT NOT NULL, quantity DOUBLE PRECISION DEFAULT NULL, unit VARCHAR(10) DEFAULT NULL, item_id INT NOT NULL, recipe_id INT NOT NULL, INDEX IDX_2FBB530F126F525E (item_id), INDEX IDX_2FBB530F59D8A214 (recipe_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE shopping_list ADD CONSTRAINT FK_3DC1A459613FECDF FOREIGN KEY (session_id) REFERENCES shopping_session (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE shopping_list_item ADD CONSTRAINT FK_4FB1C2243DAE168B FOREIGN KEY (list_id) REFERENCES shopping_list (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE shopping_list_item ADD CONSTRAINT FK_4FB1C224933FE08C FOREIGN KEY (ingredient_id) REFERENCES ingredient (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE shopping_list_item_source ADD CONSTRAINT FK_2FBB530F126F525E FOREIGN KEY (item_id) REFERENCES shopping_list_item (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE shopping_list_item_source ADD CONSTRAINT FK_2FBB530F59D8A214 FOREIGN KEY (recipe_id) REFERENCES recipe (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE inventory_item ADD ingredient_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE inventory_item ADD CONSTRAINT FK_55BDEA30933FE08C FOREIGN KEY (ingredient_id) REFERENCES ingredient (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_55BDEA30933FE08C ON inventory_item (ingredient_id)');
        $this->addSql('ALTER TABLE recipe_ingredient ADD ingredient_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE recipe_ingredient ADD CONSTRAINT FK_22D1FE13933FE08C FOREIGN KEY (ingredient_id) REFERENCES ingredient (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_22D1FE13933FE08C ON recipe_ingredient (ingredient_id)');
        $this->addSql('ALTER TABLE shopping_session ADD list_inputs_version INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE shopping_list DROP FOREIGN KEY FK_3DC1A459613FECDF');
        $this->addSql('ALTER TABLE shopping_list_item DROP FOREIGN KEY FK_4FB1C2243DAE168B');
        $this->addSql('ALTER TABLE shopping_list_item DROP FOREIGN KEY FK_4FB1C224933FE08C');
        $this->addSql('ALTER TABLE shopping_list_item_source DROP FOREIGN KEY FK_2FBB530F126F525E');
        $this->addSql('ALTER TABLE shopping_list_item_source DROP FOREIGN KEY FK_2FBB530F59D8A214');
        $this->addSql('DROP TABLE ingredient');
        $this->addSql('DROP TABLE shopping_list');
        $this->addSql('DROP TABLE shopping_list_item');
        $this->addSql('DROP TABLE shopping_list_item_source');
        $this->addSql('ALTER TABLE inventory_item DROP FOREIGN KEY FK_55BDEA30933FE08C');
        $this->addSql('DROP INDEX IDX_55BDEA30933FE08C ON inventory_item');
        $this->addSql('ALTER TABLE inventory_item DROP ingredient_id');
        $this->addSql('ALTER TABLE recipe_ingredient DROP FOREIGN KEY FK_22D1FE13933FE08C');
        $this->addSql('DROP INDEX IDX_22D1FE13933FE08C ON recipe_ingredient');
        $this->addSql('ALTER TABLE recipe_ingredient DROP ingredient_id');
        $this->addSql('ALTER TABLE shopping_session DROP list_inputs_version');
    }
}
