<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261119151835 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Schemat początkowy: urządzenia, tokeny, metryki, reguły, alarmy, zdarzenia, odbiorcy, outbox, ustawienia';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE admin_user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, last_login_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_AD8A54A9E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE alert_rule (id INT AUTO_INCREMENT NOT NULL, source VARCHAR(8) NOT NULL, target VARCHAR(190) NOT NULL, operator VARCHAR(4) NOT NULL, warn_threshold DOUBLE PRECISION DEFAULT NULL, crit_threshold DOUBLE PRECISION DEFAULT NULL, consecutive_samples INT NOT NULL, hysteresis DOUBLE PRECISION NOT NULL, enabled TINYINT NOT NULL, label VARCHAR(190) DEFAULT NULL, device_id INT NOT NULL, INDEX idx_rule_device_target (device_id, source, target), INDEX IDX_C9687E4894A4C7D4 (device_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE alert_state (id INT AUTO_INCREMENT NOT NULL, subject VARCHAR(64) NOT NULL, severity VARCHAR(8) NOT NULL, candidate_severity VARCHAR(8) DEFAULT NULL, candidate_count INT NOT NULL, since DATETIME NOT NULL, last_value DOUBLE PRECISION DEFAULT NULL, last_message VARCHAR(500) DEFAULT NULL, last_notified_at DATETIME DEFAULT NULL, reminder_count INT NOT NULL, device_id INT NOT NULL, rule_id INT DEFAULT NULL, incident_id INT DEFAULT NULL, UNIQUE INDEX uniq_alert_state_subject (device_id, subject), INDEX IDX_49C9251094A4C7D4 (device_id), INDEX IDX_49C92510744E0351 (rule_id), INDEX IDX_49C9251059E53FB9 (incident_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE check_result (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(64) NOT NULL, status VARCHAR(8) NOT NULL, message VARCHAR(500) DEFAULT NULL, last_success_at DATETIME DEFAULT NULL, received_at DATETIME NOT NULL, device_id INT NOT NULL, UNIQUE INDEX uniq_check_device_name (device_id, name), INDEX IDX_669A38BC94A4C7D4 (device_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE device (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, slug VARCHAR(100) NOT NULL, description LONGTEXT DEFAULT NULL, location VARCHAR(100) DEFAULT NULL, enabled TINYINT NOT NULL, expected_interval_seconds INT NOT NULL, missed_intervals_threshold INT DEFAULT NULL, grace_seconds INT NOT NULL, status VARCHAR(16) NOT NULL, last_seen_at DATETIME DEFAULT NULL, status_changed_at DATETIME DEFAULT NULL, muted_until DATETIME DEFAULT NULL, last_snapshot JSON DEFAULT NULL, last_agent_version VARCHAR(32) DEFAULT NULL, last_ip VARCHAR(45) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_92FB68E989D9B62 (slug), INDEX idx_device_enabled_seen (enabled, last_seen_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE device_token (id INT AUTO_INCREMENT NOT NULL, prefix VARCHAR(16) NOT NULL, token_hash CHAR(64) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, last_used_at DATETIME DEFAULT NULL, device_id INT NOT NULL, UNIQUE INDEX UNIQ_99B2415CB3BC57DA (token_hash), INDEX IDX_99B2415C94A4C7D4 (device_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE event (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(24) NOT NULL, severity VARCHAR(8) DEFAULT NULL, message VARCHAR(500) NOT NULL, context JSON DEFAULT NULL, created_at DATETIME NOT NULL, device_id INT DEFAULT NULL, INDEX idx_event_device_created (device_id, created_at), INDEX idx_event_created (created_at), INDEX idx_event_type_created (type, created_at), INDEX IDX_3BAE0AA794A4C7D4 (device_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE incident (id INT AUTO_INCREMENT NOT NULL, subject VARCHAR(64) NOT NULL, title VARCHAR(190) NOT NULL, max_severity VARCHAR(8) NOT NULL, opened_at DATETIME NOT NULL, closed_at DATETIME DEFAULT NULL, device_id INT NOT NULL, INDEX idx_incident_device_opened (device_id, opened_at), INDEX IDX_3D03A11A94A4C7D4 (device_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE metric (id INT AUTO_INCREMENT NOT NULL, metric_key VARCHAR(190) NOT NULL, unit VARCHAR(16) DEFAULT NULL, UNIQUE INDEX UNIQ_87D62EE3955C4375 (metric_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE notification_outbox (id INT AUTO_INCREMENT NOT NULL, channel VARCHAR(16) NOT NULL, address VARCHAR(255) NOT NULL, subject VARCHAR(255) NOT NULL, body_text LONGTEXT NOT NULL, body_html LONGTEXT DEFAULT NULL, status VARCHAR(8) NOT NULL, attempts INT NOT NULL, next_attempt_at DATETIME NOT NULL, last_error LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, sent_at DATETIME DEFAULT NULL, recipient_id INT DEFAULT NULL, device_id INT DEFAULT NULL, INDEX idx_outbox_status_next (status, next_attempt_at), INDEX idx_outbox_created (created_at), INDEX IDX_AE402B2DE92F8F78 (recipient_id), INDEX IDX_AE402B2D94A4C7D4 (device_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE recipient (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, channel VARCHAR(16) NOT NULL, address VARCHAR(255) NOT NULL, enabled TINYINT NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sample (ts INT UNSIGNED NOT NULL, value DOUBLE PRECISION NOT NULL, device_id INT NOT NULL, metric_id INT NOT NULL, INDEX idx_sample_ts (ts), INDEX IDX_F10B76C394A4C7D4 (device_id), INDEX IDX_F10B76C3A952D583 (metric_id), PRIMARY KEY (device_id, metric_id, ts)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sample_hourly (hour_ts INT UNSIGNED NOT NULL, min_value DOUBLE PRECISION NOT NULL, avg_value DOUBLE PRECISION NOT NULL, max_value DOUBLE PRECISION NOT NULL, sample_count INT UNSIGNED NOT NULL, device_id INT NOT NULL, metric_id INT NOT NULL, INDEX idx_sample_hourly_hour (hour_ts), INDEX IDX_35B5013194A4C7D4 (device_id), INDEX IDX_35B50131A952D583 (metric_id), PRIMARY KEY (device_id, metric_id, hour_ts)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE setting (name VARCHAR(64) NOT NULL, value JSON NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (name)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE subscription (id INT AUTO_INCREMENT NOT NULL, min_severity VARCHAR(8) NOT NULL, notify_recovery TINYINT NOT NULL, recipient_id INT NOT NULL, device_id INT DEFAULT NULL, INDEX IDX_A3C664D3E92F8F78 (recipient_id), INDEX IDX_A3C664D394A4C7D4 (device_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE alert_rule ADD CONSTRAINT FK_C9687E4894A4C7D4 FOREIGN KEY (device_id) REFERENCES device (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE alert_state ADD CONSTRAINT FK_49C9251094A4C7D4 FOREIGN KEY (device_id) REFERENCES device (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE alert_state ADD CONSTRAINT FK_49C92510744E0351 FOREIGN KEY (rule_id) REFERENCES alert_rule (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE alert_state ADD CONSTRAINT FK_49C9251059E53FB9 FOREIGN KEY (incident_id) REFERENCES incident (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE check_result ADD CONSTRAINT FK_669A38BC94A4C7D4 FOREIGN KEY (device_id) REFERENCES device (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE device_token ADD CONSTRAINT FK_99B2415C94A4C7D4 FOREIGN KEY (device_id) REFERENCES device (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE event ADD CONSTRAINT FK_3BAE0AA794A4C7D4 FOREIGN KEY (device_id) REFERENCES device (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE incident ADD CONSTRAINT FK_3D03A11A94A4C7D4 FOREIGN KEY (device_id) REFERENCES device (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notification_outbox ADD CONSTRAINT FK_AE402B2DE92F8F78 FOREIGN KEY (recipient_id) REFERENCES recipient (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE notification_outbox ADD CONSTRAINT FK_AE402B2D94A4C7D4 FOREIGN KEY (device_id) REFERENCES device (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE sample ADD CONSTRAINT FK_F10B76C394A4C7D4 FOREIGN KEY (device_id) REFERENCES device (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sample ADD CONSTRAINT FK_F10B76C3A952D583 FOREIGN KEY (metric_id) REFERENCES metric (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sample_hourly ADD CONSTRAINT FK_35B5013194A4C7D4 FOREIGN KEY (device_id) REFERENCES device (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sample_hourly ADD CONSTRAINT FK_35B50131A952D583 FOREIGN KEY (metric_id) REFERENCES metric (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE subscription ADD CONSTRAINT FK_A3C664D3E92F8F78 FOREIGN KEY (recipient_id) REFERENCES recipient (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE subscription ADD CONSTRAINT FK_A3C664D394A4C7D4 FOREIGN KEY (device_id) REFERENCES device (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE alert_rule DROP FOREIGN KEY FK_C9687E4894A4C7D4');
        $this->addSql('ALTER TABLE alert_state DROP FOREIGN KEY FK_49C9251094A4C7D4');
        $this->addSql('ALTER TABLE alert_state DROP FOREIGN KEY FK_49C92510744E0351');
        $this->addSql('ALTER TABLE alert_state DROP FOREIGN KEY FK_49C9251059E53FB9');
        $this->addSql('ALTER TABLE check_result DROP FOREIGN KEY FK_669A38BC94A4C7D4');
        $this->addSql('ALTER TABLE device_token DROP FOREIGN KEY FK_99B2415C94A4C7D4');
        $this->addSql('ALTER TABLE event DROP FOREIGN KEY FK_3BAE0AA794A4C7D4');
        $this->addSql('ALTER TABLE incident DROP FOREIGN KEY FK_3D03A11A94A4C7D4');
        $this->addSql('ALTER TABLE notification_outbox DROP FOREIGN KEY FK_AE402B2DE92F8F78');
        $this->addSql('ALTER TABLE notification_outbox DROP FOREIGN KEY FK_AE402B2D94A4C7D4');
        $this->addSql('ALTER TABLE sample DROP FOREIGN KEY FK_F10B76C394A4C7D4');
        $this->addSql('ALTER TABLE sample DROP FOREIGN KEY FK_F10B76C3A952D583');
        $this->addSql('ALTER TABLE sample_hourly DROP FOREIGN KEY FK_35B5013194A4C7D4');
        $this->addSql('ALTER TABLE sample_hourly DROP FOREIGN KEY FK_35B50131A952D583');
        $this->addSql('ALTER TABLE subscription DROP FOREIGN KEY FK_A3C664D3E92F8F78');
        $this->addSql('ALTER TABLE subscription DROP FOREIGN KEY FK_A3C664D394A4C7D4');
        $this->addSql('DROP TABLE admin_user');
        $this->addSql('DROP TABLE alert_rule');
        $this->addSql('DROP TABLE alert_state');
        $this->addSql('DROP TABLE check_result');
        $this->addSql('DROP TABLE device');
        $this->addSql('DROP TABLE device_token');
        $this->addSql('DROP TABLE event');
        $this->addSql('DROP TABLE incident');
        $this->addSql('DROP TABLE metric');
        $this->addSql('DROP TABLE notification_outbox');
        $this->addSql('DROP TABLE recipient');
        $this->addSql('DROP TABLE sample');
        $this->addSql('DROP TABLE sample_hourly');
        $this->addSql('DROP TABLE setting');
        $this->addSql('DROP TABLE subscription');
    }
}
