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
    public const MODULE_APACHE    = 'apache';
    public const MODULE_NGINX     = 'nginx';
    public const MODULE_ALL       = 'all';
    public const DEFAULT_MODULE   = 'all';
    private const VALID_MODULES   = [
        self::MODULE_WORDPRESS,
        self::MODULE_APACHE,
        self::MODULE_NGINX,
        self::MODULE_ALL,
    ];
    public const DEFAULT_MAX_AGE_IN_DAYS = 365;
    public const DEFAULT_MAX_REDIRECTS   = 5;
    private const LOG_AS_GOOD            = 'good';
    private const LOG_AS_WARNING         = 'warning';
    private const LOG_AS_BAD             = 'bad';

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
    private array $flags;

    /**
     * @inheritDoc
     */
    public function __construct( $pluginName, $version ) {
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

        parent::__construct($pluginName, $version);
    }

    /**
     * Removes the temporary redirect export file on exit
     */
    public function __destruct()
    {
        if (
            $this->redirection_export_file_location
            && file_exists( $this->redirection_export_file_location )
        ) {
            WP_CLI::log(
                sprintf( 'Removing the export file from %s.', $this->redirection_export_file_location )
            );
            wp_delete_file( $this->redirection_export_file_location );
        }
    }

    /**
     * Audit http redirects from the Redirection plugin to check for 404's, loops etc
     * 
     * [--module=<wordpress|apache|nginx|all>]
     * : Which module to test. Defaults to 'all'
     * 
     * [--max-redirects=<count>]
     * : How many redirects to follow before giving up. Defaults to 5.
     * 
     * [--max-age=<days>]
     * : How many days since a redirect was hit is considered "old". Defaults to 365
     * 
     * [--verbose]
     * : Show passes as well as failures, and extra info in general.
     * 
     * [--ids=<id>...]
     * : Array of redirect IDs to test. Useful for retesting a subset from an earlier full audit
     * 
     * [--match-url=<url>]
     * : Check a single match-url. Copy and paste this into quotes from the Redirection page in wp-admin
     */
    public function __invoke( array $args, array $flags ):void
    {
        $this->flags = wp_parse_args(
            $flags,
            [
                'module'        => $this::DEFAULT_MODULE,
                'max-age'       => $this::DEFAULT_MAX_AGE_IN_DAYS,
                'max-redirects' => $this::DEFAULT_MAX_REDIRECTS,
                'verbose'       => false,
                'ids'           => [],
                'match-url'  => null
            ]
        );

        if ( ! $this->passes_sanity_check() || ! $this->validate_flags() ) {
            WP_CLI::error('Failed to start');
        }

        // Export all redirects
        $redirection_data = $this->export_redirects();
        if ( ! ( $redirection_data['redirects'] ?? false ) ) {
            WP_CLI::success( 'Redirects exported, but no actual redirect rules found. Nothing to do.' );
            return;
        }
        WP_CLI::log( sprintf( 'Redirection plugin version %s', $redirection_data['plugin']['version'] ?? 'unknown' ) );
        WP_CLI::log( sprintf( 'Exported %d redirects', count( $redirection_data['redirects'] ) ) );

        // Filter by flags if required
        $redirection_data['redirects'] = $this->filter_results_by_ids( $redirection_data['redirects'] );
        $redirection_data['redirects'] = $this->filter_results_by_url( $redirection_data['redirects'] );

        WP_CLI::log('-------');
        $this->test_redirects( $redirection_data['redirects'] );
        $this->display_result_stats();
        WP_CLI::success( 'Done' );
    }

    private function display_result_stats():void {
        WP_CLI::log('-------');
        foreach( array_keys( $this->report ) as $report_section ) {
            WP_CLI::log(
                sprintf(
                    '%d %s found.',
                    count( $this->report[ $report_section ] ),
                    $report_section
                )
            );
        }
        WP_CLI::log('-------');
    }

    /**
     * Basic checks for the Redirection plugin existing, and the export file being writable
     * 
     * @return bool $success
     */
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

    /**
     * Parse, and sanity check any flags passed in
     * 
     * @return bool $success
     */
    protected function validate_flags():bool
    {
        if ( ! in_array( $this->flags['module'], self::VALID_MODULES ) ) {
            WP_CLI::error(
                sprintf(
                    'Selected module "%s" is invalid. Valid options are %s',
                    $this->flags['module'],
                    implode(', ', self::VALID_MODULES )
                )
            );
            return false;
        }

        if ( $this->flags['ids'] ) {
            $this->flags['ids'] = explode( ',', $this->flags['ids'] );
            foreach ( $this->flags['ids'] as &$flag ) {
                $flag = (int)trim($flag);
                if ( ! $flag ) {
                    WP_CLI::error( 'Invalid ID found. Expected a csv of ints.' );
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Call the Redirection CLI to export them all to a temporary json file
     * 
     * @return array $redirection_data An associative array of data about the plugin, and the redirects themselves.
     */
    private function export_redirects():array {
        $command = sprintf( 'redirection export %s %s', $this->flags['module'], $this->redirection_export_file_location );
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

    /**
     * Filter the redirects that were exported to only include the IDs passed in by the --ids= flag
     * 
     * @param array $redirects
     * @return array $filtered_redirects
     */
    private function filter_results_by_ids( array $redirects ):array {
        if ( ! $this->flags['ids'] ) {
            return $redirects;
        }

        $redirects = array_filter( $redirects, fn( $redirect ) => in_array( $redirect['id'], $this->flags['ids'] ) );

        if ( count( $this->flags['ids'] ) !== count( $redirects ) ) {
            WP_CLI::error(
                sprintf(
                    'Not all --ids were found. Expected to find %d, found %d.',
                    count( $this->flags['ids'] ),
                    count( $redirects )
                )
            );
        }

        WP_CLI::log(
            sprintf(
                'Results have been filtered to %d redirects using the --ids flag',
                count( $this->flags['ids'] )
            )
        );

        return $redirects;
    }

    /**
     * Remove all redirects except the one referenced explicitly in the flag
     * 
     * @param array $redirects
     * @return array $filtered_redirects
     */
    private function filter_results_by_url( array $redirects ):array {
        if ( ! $this->flags['matching-url'] ) {
            return $redirects;
        }

        $redirects = array_filter( $redirects, fn( $redirect ) => in_array( $redirect['match_url'], $this->flags['match-url'] ) );

        if ( ! count( $redirects ) ) {
            WP_CLI::error(
                sprintf(
                    'match-id of "%s" not found.',
                    $this->flags['match-url']
                )
            );
        }

        WP_CLI::log(
            sprintf(
                'Results have been filtered to %d redirect using the --match-url flag',
                count( $redirects )
            )
        );

        return $redirects;
    }

    /**
     * Loop over redirects and send each for testing
     * 
     * @param array $redirects The array of redirect arrays, exported from Redirection
     */
    private function test_redirects( array $redirects ):void
    {
        WP_CLI::log( sprintf( 'Testing %d redirects', count( $redirects ) ) );
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
                if ( $days_since_last_hit > $this->flags['max-age'] ) {
                    $this->log( $redirect, self::LOG_AS_WARNING, sprintf( 'The redirect has not been hit in %d days. Consider removing.', $days_since_last_hit ) );
                    $this->report['is_old'][] = $redirect;
                }
            }

            print_r($redirect);

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

    /**
     * Evaluate a single redirect
     * 
     * @param array $redirect A single redirect array from the Redirection export
     * @return void
     * @throws WpHttpException
     */
    private function test_url_redirect( array $redirect ):void
    {
        $has_warnings = false;

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
            $redirection_chain = $this->evaluate_redirection_chain( $url_to_test );
        } catch( WpHttpException $e ) {
            $this->log( $redirect, self::LOG_AS_BAD, $e->getMessage() );
            return;
        }
        
        if ( ! count( $redirection_chain ) ) {
            $this->log( $redirect, self::LOG_AS_BAD, 'No redirection detected (no response)' );
            return;
        }

        // The chain should be 2; a 3xx and a 2xx
        if ( count( $redirection_chain ) > 2 ) {
            $has_warnings = true;
            $this->log(
                $redirect,
                self::LOG_AS_WARNING,
                sprintf(
                    'Inefficient redirection chain. Counted %d redirects',
                    count( $redirection_chain ) - 1
                )
            );
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
            print_r($response['response']);
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

        if ( ! $has_warnings ) {
            $this->log( $redirect, self::LOG_AS_GOOD );
        }
    }

    /**
     * Recursively walk the redirection chain
     * 
     * @param string $url The full URL to check
     * @param array $redirection_chain An array of ['code'=>int,'location'=>string] of the redirects followed
     * @return array The updated $redirection_chain
     * @throws WpHttpException
     */
    private function evaluate_redirection_chain(
        string $url,
        array $redirect_chain = []
        
    ):array {
        if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            throw new WpHttpException(
                sprintf(
                    'URL "%s" in the chain is not valid.',
                    $url,
                )
            );
        }

        if ( count( $redirect_chain ) >= $this->flags['max-redirects'] ) {
            throw new WpHttpException(
                sprintf(
                    'Hit the max number redirection limit of %d',
                    $this->flags['max-redirects'],
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
            $redirect_chain = $this->evaluate_redirection_chain(
                $this->base_url . $this_redirect['location'],
                $redirect_chain
            );
        }

        return $redirect_chain;
    }

    /**
     * Send info to STDOUT about the redirect
     * 
     * @param array $redirect The array object from the Redirection export that the message is concerning
     * @param string $log_as Enum of 'good', 'warning', 'bad'
     * @param string $reason An optional message to give further context
     */
    private function log( array $redirect, string $log_as, string $reason = '' ):void
    {
        $message = sprintf(
            'Redirect #%d, matching url "%s", is bad: %s',
            $redirect['id'],
            $redirect['match_url'] ?? 'url missing',
            $reason
        );

        switch ( $log_as ) {
            case self::LOG_AS_GOOD:
                if ( $this->flags['verbose'] ) {
                    WP_CLI::log(
                        sprintf(
                            'Redirect #%d, matching url "%s", is good.',
                            $redirect['id'],
                            $redirect['match_url'] ?? 'url missing'
                        )
                    );
                }
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
