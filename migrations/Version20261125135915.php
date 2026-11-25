<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261125135915 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Wybór wskaźników na karcie pulpitu (device.dashboard_indicators)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE device ADD dashboard_indicators JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE device DROP dashboard_indicators');
    }
}
