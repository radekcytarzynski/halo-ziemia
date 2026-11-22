<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261122160044 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Nazwy dysków i dysk na karcie dashboardu (device.disk_labels, device.dashboard_disk)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE device ADD disk_labels JSON DEFAULT NULL, ADD dashboard_disk VARCHAR(151) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE device DROP disk_labels, DROP dashboard_disk');
    }
}
