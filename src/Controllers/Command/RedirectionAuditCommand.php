<?php

namespace OnionWordpressDeveloperToolbox\Controllers\Command;

use DateTimeImmutable;
use \WP_CLI;

class RedirectionAuditCommand extends AbstractCommandController
{
    public const COMMAND_NAME = 'redirection-audit';

    public const MODULE_WORDPRESS = 'wordpress';
    public const MODULE_APACHE = 'apache';
    public const MODULE_NGINX = 'nginx';
    public const MODULE_ALL = 'all';
    public const DEFAULT_MODULE = 'all';
    private const VALID_MODULES = [
        self::MODULE_WORDPRESS,
        self::MODULE_APACHE,
        self::MODULE_NGINX,
        self::MODULE_ALL,
    ];
    public const DEFAULT_MAX_AGE_IN_DAYS = 365;

    private string $redirection_export_file_location = '';

    private array $report = [
        'enabled' => [],
        'disabled' => [],
        'never_hit' => [],
        'is_old' => [],
    ];

    /**
     * Tests redirections from the Redirection plugin to check for 404's, loops etc
     * 
     * [--module=<wordpress|apache|nginx|all>]
     * : Which module to test
     * 
     * [--max-age=<int>]
     * : How many days since a redirect was hit is considered "old"
     */
    public function __invoke( array $args, array $flags ):void
    {
        $flags = wp_parse_args(
            $flags,
            [
                'module' => $this::DEFAULT_MODULE,
                'max-age' => $this::DEFAULT_MAX_AGE_IN_DAYS,
            ]
        );

        if (
            ! $this->passes_sanity_check()
            || ! $this->validate_args( $args, $flags )
        ) {
            WP_CLI::error('Failed to start');
        }

        $redirection_data = $this->export_redirects( $flags['module'] );
        if ( ! ( $redirection_data['redirects'] ?? false ) ) {
            WP_CLI::success( 'Redirects exported, but no actual redirect rules found. Nothing to do.' );
            return;
        }

        WP_CLI::log(
            sprintf( 'Redirection plugin version %s', $redirection_data['plugin']['version'] ?? 'unknown' )
        );

        $this->test_redirects(
            $redirection_data['redirects'],
            $flags['max-age']
        );

        print_r($this->report);

        WP_CLI::success( 'Done' );
    }

    public function __destruct()
    {
        if (
            $this->redirection_export_file_location
            && file_exists( $this->redirection_export_file_location )
        ) {
            WP_CLI::log('Removing the export file.');
            //wp_delete_file( $this->redirection_export_file_location );
        }
    }

    private function passes_sanity_check():bool {
        if ( ! is_plugin_active( 'redirection/redirection.php' ) ) {
            WP_CLI::log( 'The Redirection plugin was not found or was not active. Nothing to audit.' );
            return false;
        }

        $upload_dir = wp_upload_dir();
        if (
            ! ($upload_dir['path'] ?? false )
            || ! wp_is_writable( $upload_dir['path'] )
        ) {
            WP_CLI::log( 'Failed to ascertain the uploads path, or path is not writeable' );
            return false;
        }
        $this->redirection_export_file_location = $upload_dir['path'] . '/redirectionsExport.json';

        return true;
    }

    protected function validate_args( array $args, array $flags ):bool
    {
        if ( ! in_array( $flags['module'], self::VALID_MODULES ) ) {
            WP_CLI::log(
                sprintf(
                    'Selected module "%s" is invalid. Valid options are %s',
                    $flags['module'],
                    implode(', ', self::VALID_MODULES )
                )
            );
            return false;
        }

        return true;
    }

    private function export_redirects( string $module ):array {
        $command = sprintf( 'redirection export %s %s', $module, $this->redirection_export_file_location );
        $response = WP_CLI::runcommand(
            $command,
            [
                'return'       => 'all', // Return 'STDOUT'; use 'all' for full object.
                'parse'        => false, // Parse captured STDOUT to JSON array.
                'launch'       => false, // Reuse the current process.
                'exit_error'   => false, // Halt script execution on error.
                'command_args' => [ '--skip-themes', '--quiet' ], // Additional arguments to be passed to the $command.
            ]
        );

        if ( ( $response->return_code ?? '') !== 0 ) {
            WP_CLI::error( sprintf( 'Non-zero response from command "%s"', $command ), false );
            WP_CLI::error( sprintf( 'STDOUT was "%s"', $response->stdout ?? '' ), false );
            WP_CLI::error('Quitting');
        }

        if ( ! file_exists( $this->redirection_export_file_location ) ) {
            WP_CLI::error( sprintf( 'Export file not found at "%s".', $this->redirection_export_file_location ), false );
            WP_CLI::error('Quitting');
        }

        $redirection_data = json_decode( file_get_contents( $this->redirection_export_file_location ), true );
        if (
            ! $redirection_data
            || ! is_array( $redirection_data )
            || json_last_error() !== JSON_ERROR_NONE
        ) {
            WP_CLI::error( sprintf( 'Export file could not be parsed from "%s".', $this->redirection_export_file_location ), false );
            WP_CLI::error('Quitting');
        }

        return $redirection_data;
    }

    private function test_redirects( array $redirects, int $max_age ):void
    {
        WP_CLI::log( sprintf( 'Found %d redirects to test.', count( $redirects ) ) );
        $now = new DateTimeImmutable( 'now' );

        foreach( $redirects as $redirect ) {
            if ( ! $redirect['enabled'] ) {
                $this->report['disabled'][] = $redirect;
                continue;
            }

            $this->report['enabled'] = $redirect;

            if ( $redirect['hits'] === 0 ) {
                $this->report['never_hit'][] = $redirect;
            } else {
                $days_since_last_hit = $now->diff( new DateTimeImmutable( $redirect['last_access'] ?? 'now' ), true);
                if ( $days_since_last_hit > $max_age ) {
                    $this->report['is_old'][] = $redirect;
                }
            }

            switch( $redirect['match_type'] ?? '' ) {
                case 'url':
                    $this->test_url_redirect( $redirect );
                    break;
                    
                default:
                    WP_CLI::error( sprintf( 'Unknown redirect match_type of "%s"', $redirect['match_type'] ?? '' ) );
                    break;
            }
        }
    }

    private function test_url_redirect( array $redirect ):void
    {
        
    }
}
