<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261104152914 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Étape 4 — Mémoire : user_memory, memory_event';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE memory_event (id INT AUTO_INCREMENT NOT NULL, action VARCHAR(10) NOT NULL, category VARCHAR(30) NOT NULL, old_value VARCHAR(200) DEFAULT NULL, new_value VARCHAR(200) DEFAULT NULL, origin VARCHAR(10) NOT NULL, reason LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, undone_at DATETIME DEFAULT NULL, user_id INT NOT NULL, session_id INT DEFAULT NULL, message_id INT DEFAULT NULL, memory_id INT DEFAULT NULL, INDEX IDX_DCB914CBA76ED395 (user_id), INDEX IDX_DCB914CB613FECDF (session_id), INDEX IDX_DCB914CB537A1329 (message_id), INDEX IDX_DCB914CBCCC80CB3 (memory_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_memory (id INT AUTO_INCREMENT NOT NULL, category VARCHAR(30) NOT NULL, value VARCHAR(200) NOT NULL, normalized_value VARCHAR(200) NOT NULL, details JSON DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, source_session_id INT DEFAULT NULL, UNIQUE INDEX uniq_user_memory_value (user_id, category, normalized_value), INDEX IDX_8F3F2F08A76ED395 (user_id), INDEX IDX_8F3F2F081D8B9DFB (source_session_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE memory_event ADD CONSTRAINT FK_DCB914CBA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE memory_event ADD CONSTRAINT FK_DCB914CB613FECDF FOREIGN KEY (session_id) REFERENCES shopping_session (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE memory_event ADD CONSTRAINT FK_DCB914CB537A1329 FOREIGN KEY (message_id) REFERENCES message (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE memory_event ADD CONSTRAINT FK_DCB914CBCCC80CB3 FOREIGN KEY (memory_id) REFERENCES user_memory (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE user_memory ADD CONSTRAINT FK_8F3F2F08A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_memory ADD CONSTRAINT FK_8F3F2F081D8B9DFB FOREIGN KEY (source_session_id) REFERENCES shopping_session (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE memory_event DROP FOREIGN KEY FK_DCB914CBA76ED395');
        $this->addSql('ALTER TABLE memory_event DROP FOREIGN KEY FK_DCB914CB613FECDF');
        $this->addSql('ALTER TABLE memory_event DROP FOREIGN KEY FK_DCB914CB537A1329');
        $this->addSql('ALTER TABLE memory_event DROP FOREIGN KEY FK_DCB914CBCCC80CB3');
        $this->addSql('ALTER TABLE user_memory DROP FOREIGN KEY FK_8F3F2F08A76ED395');
        $this->addSql('ALTER TABLE user_memory DROP FOREIGN KEY FK_8F3F2F081D8B9DFB');
        $this->addSql('DROP TABLE memory_event');
        $this->addSql('DROP TABLE user_memory');
    }
}
