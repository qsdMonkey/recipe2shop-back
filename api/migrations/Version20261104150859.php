<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261104150859 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Étape 3 — Moteur de conversation : shopping_session, conversation_turn, message, generation_log, app_user.onboarding_completed_at';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE conversation_turn (id INT AUTO_INCREMENT NOT NULL, client_id VARCHAR(36) DEFAULT NULL, kind VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, started_at DATETIME NOT NULL, completed_at DATETIME DEFAULT NULL, session_id INT NOT NULL, UNIQUE INDEX uniq_turn_session_client (session_id, client_id), INDEX IDX_F75F765B613FECDF (session_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE generation_log (id INT AUTO_INCREMENT NOT NULL, purpose VARCHAR(30) NOT NULL, model VARCHAR(100) NOT NULL, prompt_version VARCHAR(50) NOT NULL, input_tokens INT NOT NULL, output_tokens INT NOT NULL, thinking_tokens INT NOT NULL, cost_usd NUMERIC(12, 6) NOT NULL, duration_ms INT NOT NULL, success TINYINT NOT NULL, error LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, user_id INT DEFAULT NULL, session_id INT DEFAULT NULL, INDEX idx_generation_log_created (created_at), INDEX IDX_78B73C91A76ED395 (user_id), INDEX IDX_78B73C91613FECDF (session_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE message (id INT AUTO_INCREMENT NOT NULL, role VARCHAR(20) NOT NULL, content LONGTEXT NOT NULL, blocks JSON NOT NULL, tool_calls JSON DEFAULT NULL, created_at DATETIME NOT NULL, session_id INT NOT NULL, turn_id INT DEFAULT NULL, INDEX IDX_B6BD307F613FECDF (session_id), INDEX IDX_B6BD307F1F4F9889 (turn_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE shopping_session (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, started_at DATETIME NOT NULL, closed_at DATETIME DEFAULT NULL, period_days INT NOT NULL, summary LONGTEXT DEFAULT NULL, last_message_at DATETIME DEFAULT NULL, turn_locked_at DATETIME DEFAULT NULL, user_id INT NOT NULL, INDEX idx_session_user_type_status (user_id, type, status), INDEX IDX_CECE98A6A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE conversation_turn ADD CONSTRAINT FK_F75F765B613FECDF FOREIGN KEY (session_id) REFERENCES shopping_session (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE generation_log ADD CONSTRAINT FK_78B73C91A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE generation_log ADD CONSTRAINT FK_78B73C91613FECDF FOREIGN KEY (session_id) REFERENCES shopping_session (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT FK_B6BD307F613FECDF FOREIGN KEY (session_id) REFERENCES shopping_session (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT FK_B6BD307F1F4F9889 FOREIGN KEY (turn_id) REFERENCES conversation_turn (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE shopping_session ADD CONSTRAINT FK_CECE98A6A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE app_user ADD onboarding_completed_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE conversation_turn DROP FOREIGN KEY FK_F75F765B613FECDF');
        $this->addSql('ALTER TABLE generation_log DROP FOREIGN KEY FK_78B73C91A76ED395');
        $this->addSql('ALTER TABLE generation_log DROP FOREIGN KEY FK_78B73C91613FECDF');
        $this->addSql('ALTER TABLE message DROP FOREIGN KEY FK_B6BD307F613FECDF');
        $this->addSql('ALTER TABLE message DROP FOREIGN KEY FK_B6BD307F1F4F9889');
        $this->addSql('ALTER TABLE shopping_session DROP FOREIGN KEY FK_CECE98A6A76ED395');
        $this->addSql('DROP TABLE conversation_turn');
        $this->addSql('DROP TABLE generation_log');
        $this->addSql('DROP TABLE message');
        $this->addSql('DROP TABLE shopping_session');
        $this->addSql('ALTER TABLE app_user DROP onboarding_completed_at');
    }
}
