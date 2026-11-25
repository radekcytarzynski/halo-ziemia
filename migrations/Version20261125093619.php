<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261125093619 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tabele próbek tylko z kluczem głównym: bez kluczy obcych i indeksów dodatkowych (ok. połowa rozmiaru)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sample DROP FOREIGN KEY `FK_F10B76C394A4C7D4`');
        $this->addSql('ALTER TABLE sample DROP FOREIGN KEY `FK_F10B76C3A952D583`');
        $this->addSql('DROP INDEX IDX_F10B76C394A4C7D4 ON sample');
        $this->addSql('DROP INDEX IDX_F10B76C3A952D583 ON sample');
        $this->addSql('DROP INDEX idx_sample_ts ON sample');
        $this->addSql('ALTER TABLE sample_hourly DROP FOREIGN KEY `FK_35B5013194A4C7D4`');
        $this->addSql('ALTER TABLE sample_hourly DROP FOREIGN KEY `FK_35B50131A952D583`');
        $this->addSql('DROP INDEX IDX_35B5013194A4C7D4 ON sample_hourly');
        $this->addSql('DROP INDEX IDX_35B50131A952D583 ON sample_hourly');
        $this->addSql('DROP INDEX idx_sample_hourly_hour ON sample_hourly');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sample ADD CONSTRAINT `FK_F10B76C394A4C7D4` FOREIGN KEY (device_id) REFERENCES device (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sample ADD CONSTRAINT `FK_F10B76C3A952D583` FOREIGN KEY (metric_id) REFERENCES metric (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_F10B76C394A4C7D4 ON sample (device_id)');
        $this->addSql('CREATE INDEX IDX_F10B76C3A952D583 ON sample (metric_id)');
        $this->addSql('CREATE INDEX idx_sample_ts ON sample (ts)');
        $this->addSql('ALTER TABLE sample_hourly ADD CONSTRAINT `FK_35B5013194A4C7D4` FOREIGN KEY (device_id) REFERENCES device (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sample_hourly ADD CONSTRAINT `FK_35B50131A952D583` FOREIGN KEY (metric_id) REFERENCES metric (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_35B5013194A4C7D4 ON sample_hourly (device_id)');
        $this->addSql('CREATE INDEX IDX_35B50131A952D583 ON sample_hourly (metric_id)');
        $this->addSql('CREATE INDEX idx_sample_hourly_hour ON sample_hourly (hour_ts)');
    }
}
