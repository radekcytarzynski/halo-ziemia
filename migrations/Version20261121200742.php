<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261121200742 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Silnik alarmów: tytuł i notified_severity w alert_state, dłuższe klucze podmiotów';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE alert_state ADD title VARCHAR(255) NOT NULL, ADD last_observed_at DATETIME DEFAULT NULL, ADD notified_severity VARCHAR(8) DEFAULT NULL, CHANGE subject subject VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE incident CHANGE subject subject VARCHAR(255) NOT NULL, CHANGE title title VARCHAR(255) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE alert_state DROP title, DROP last_observed_at, DROP notified_severity, CHANGE subject subject VARCHAR(64) NOT NULL');
        $this->addSql('ALTER TABLE incident CHANGE subject subject VARCHAR(64) NOT NULL, CHANGE title title VARCHAR(190) NOT NULL');
    }
}
