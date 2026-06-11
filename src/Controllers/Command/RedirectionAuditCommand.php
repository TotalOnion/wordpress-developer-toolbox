<?php

namespace OnionWordpressDeveloperToolbox\Controllers\Command;

use \DateTimeImmutable;
use \WP_CLI;
use \WP_Http;
use OnionWordpressDeveloperToolbox\Exceptions\WpHttpException;

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
    public const DEFAULT_MAX_REDIRECTS = 5;
    private const LOG_AS_GOOD = 'good';
    private const LOG_AS_WARNING = 'warning';
    private const LOG_AS_BAD = 'bad';

    private string $base_url = '';
    private string $redirection_export_file_location = '';
    private array $report = [
        'enabled' => [],
        'disabled' => [],
        'never_hit' => [],
        'is_old' => [],
        'is_bad' => [],
        'has_warnings' => [],
    ];
    private ?WP_Http $request;

    /**
     * Tests redirections from the Redirection plugin to check for 404's, loops etc
     * 
     * [--module=<wordpress|apache|nginx|all>]
     * : Which module to test
     * 
     * [--max-redirects=<int>]
     * : How many redirects to follow before giving up
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
                'max-redirects' => $this::DEFAULT_MAX_REDIRECTS,
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

        $this->request = new WP_Http;

        // don't use get_site_url() as that can be forced to be the true domain on sites split over multiple instances
        if ( 
            ($_ENV['LANDO_APP_NAME'] ?? false)
            && ($_ENV['LANDO_DOMAIN'] ?? false)
        ) {
            $this->base_url = sprintf( 'https://%s.%s', $_ENV['LANDO_APP_NAME'], $_ENV['LANDO_DOMAIN'] );
        } else {
            $this->base_url = get_option( 'siteurl' );
        }

        WP_CLI::log(
            sprintf( 'Redirection plugin version %s', $redirection_data['plugin']['version'] ?? 'unknown' )
        );

        $this->test_redirects(
            $redirection_data['redirects'],
            $flags['max-age'],
            $flags['max-redirects']
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
            WP_CLI::log(
                sprintf( 'Removing the export file from %s.', $this->redirection_export_file_location )
            );
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

    private function test_redirects( array $redirects, int $max_age, int $max_redirects ):void
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
                    $this->test_url_redirect( $redirect, $max_redirects );
                    break;
                    
                default:
                    WP_CLI::error( sprintf( 'Unknown redirect match_type of "%s"', $redirect['match_type'] ?? '' ) );
                    break;
            }
        }
    }

    private function test_url_redirect( array $redirect, int $max_redirects ):void
    {
        if ( ! ( $redirect['match_url'] ?? false ) ) {
            $this->log( $redirect, self::LOG_AS_BAD, 'Bad or missing match_url in the redirect' );
            return;
        }

        if ( ( $redirect['match_data']['source']['flag_query'] ?? false ) !== 'exact' ) {
            $this->log(
                $redirect,
                self::LOG_AS_BAD,
                sprintf( 'Match type "%s" not yet implemented', $redirect['match_data']['source']['flag_query'] ?? 'unknown' )
            );
            return;
        }

        $url_to_test = $this->base_url . $redirect['match_url'];

        // Add a trailing slash?
        if (
            ($redirect['match_data']['source']['flag_trailing'] ?? false)
            && substr($redirect['match_url'], -1, 1) !== '/'
        ) {
            $url_to_test .= '/';
        }

        $redirection_chain = [];
        try {
            $redirection_chain = $this->test_redirection_chain( $url_to_test, $max_redirects );
        } catch( WpHttpException $e ) {
            $this->log( $redirect, self::LOG_AS_BAD, $e->getMessage() );
            return;
        }
        
        if ( ! count( $redirection_chain ) ) {
            $this->log( $redirect, self::LOG_AS_BAD, 'No redirection detected (no response)' );
            return;
        }
        
        // Check the response code of the first response
        if ( $redirection_chain[0]['code'] !== ( $redirect['action_code'] ?? false ) ) {
            $this->log(
                $redirect,
                self::LOG_AS_BAD,
                sprintf(
                    'Incorrect response code. Expected %s, received %s',
                    $redirect['action_code'] ?? 'unknown',
                    $response['response']['code'] ?? 'unknown'
                )
            );
            return;
        }

        $final_url = $redirection_chain[ count( $redirection_chain ) - 2 ]['location'] ?? false;
        if ( $final_url !== ( $redirect['action_data']['url'] ?? true ) ) {
            $this->log(
                $redirect,
                self::LOG_AS_BAD,
                sprintf(
                    'Incorrect final destination. Expected %s, received %s',
                    $redirect['action_data']['url'] ?? 'unknown',
                    $final_url ?: 'unknown'
                )
            );
            return;
        }

        if ( count( $redirection_chain ) > 2 ) {
            $this->log(
                $redirect,
                self::LOG_AS_WARNING,
                sprintf(
                    'Inefficient redirection chain. Chain length is %d',
                    count( $redirection_chain ) - 1
                )
            );
        } else {
            $this->log( $redirect, self::LOG_AS_GOOD );
        }
    }

    private function test_redirection_chain(
        string $url,
        int $max_redirects,
        array $redirect_chain = []
        
    ):array {
        if ( count( $redirect_chain ) >= $max_redirects ) {
            throw new WpHttpException(
                sprintf(
                    'Hit the max number redirection limit of %d',
                    $max_redirects,
                )
            );
        }

        $response = $this->request->get( $url, [ 'redirection' => 0 ] );
        if ( is_wp_error( $response ) ) {
            throw new WpHttpException(
                sprintf(
                    'Request to fetch the URL gave an error; "%s"',
                    $response->get_error_message()
                )
            );
        }

        $headers = $response['http_response']->get_headers();
        $this_redirect = [
            'code' => $response['response']['code'],
            'location' => $headers['location'] ?? '',
        ];
        $redirect_chain[] = $this_redirect;

        // if it's a 3xx response, do some good old recursion!
        if ( $this_redirect['code'] >= 300 && $this_redirect['code'] < 400 ) {
            if ( ! $this_redirect['location'] ) {
                throw new WpHttpException(
                    sprintf( 'Received a %s response code, but with no location URL to redirect to.', $this_redirect['code'] )
                );
            }
            $redirect_chain = $this->test_redirection_chain(
                $this->base_url . $this_redirect['location'],
                $max_redirects,
                $redirect_chain
            );
        }

        return $redirect_chain;
    }

    private function log( array $redirect, string $log_as, string $reason = '' ) {
        $message = sprintf(
            'Redirect #%d, matching url "%s", is bad: %s',
            $redirect['id'],
            $redirect['match_url'] ?? 'url missing',
            $reason
        );

        switch ( $log_as ) {
            case self::LOG_AS_GOOD:
                WP_CLI::log(
                    sprintf(
                        'Redirect #%d, matching url "%s", is good.',
                        $redirect['id'],
                        $redirect['match_url'] ?? 'url missing'
                    )
                );
                break;

            case self::LOG_AS_WARNING:
                $this->report['has_warnings'][] = $redirect;
                WP_CLI::warning( $message );
                break;

            case self::LOG_AS_BAD:
            default:
                $this->report['is_bad'][] = $redirect;
                WP_CLI::error( $message, false );
                break;
        }
    }
}
