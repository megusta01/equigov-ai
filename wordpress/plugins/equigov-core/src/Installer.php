<?php

declare(strict_types=1);

namespace EquiGov\Core;

use EquiGov\Core\Auth\Roles;
use EquiGov\Core\PostTypes\Registrar;

/**
 * Cria/atualiza o schema. Versionado por opção para que cada mudança
 * de tabela seja uma migração explícita (ver docs/estudo/01).
 */
final class Installer
{
    public const DB_VERSION = '1';
    private const OPTION    = 'equigov_db_version';

    public static function activate(): void
    {
        self::migrate();
        Roles::install();
        Registrar::register();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        // Não remove dados nem papéis: desativar não é desinstalar.
        flush_rewrite_rules();
    }

    public static function maybeUpgrade(): void
    {
        if (get_option(self::OPTION) !== self::DB_VERSION) {
            self::migrate();
            Roles::install();
        }
    }

    public static function migrate(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $p       = $wpdb->prefix;

        // dbDelta é exigente: um campo por linha, dois espaços após PRIMARY KEY, KEY em vez de INDEX.
        dbDelta("CREATE TABLE {$p}eg_institutions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(191) NOT NULL,
  slug varchar(191) NOT NULL,
  sphere varchar(20) NOT NULL DEFAULT 'municipal',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug)
) {$charset};");

        dbDelta("CREATE TABLE {$p}eg_responses (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  diagnosis_id bigint(20) unsigned NOT NULL,
  institution_id bigint(20) unsigned NOT NULL,
  question_key varchar(100) NOT NULL,
  answer_value longtext NOT NULL,
  answered_by bigint(20) unsigned NOT NULL,
  answered_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY diagnosis_question (diagnosis_id,question_key),
  KEY institution_id (institution_id)
) {$charset};");

        dbDelta("CREATE TABLE {$p}eg_audit_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  institution_id bigint(20) unsigned DEFAULT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(64) NOT NULL,
  object_type varchar(32) NOT NULL,
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  context longtext NULL,
  ip_hash char(64) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY institution_created (institution_id,created_at),
  KEY object_ref (object_type,object_id)
) {$charset};");

        update_option(self::OPTION, self::DB_VERSION, false);
    }
}
