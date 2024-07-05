<?php

namespace OGame\Console\Commands\Tests;

use DateTime;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Validation\ValidationException;
use OGame\Actions\Fortify\CreateNewUser;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Planet\Coordinate;
use OGame\Models\User;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;

abstract class TestCommand extends Command
{
    /**
     * @var string The application URL that the tests will run against.
     */
    protected string $appUrl = 'http://ogame-webserver:80';

    /**
     * @var string The email of the test user.
     */
    protected string $email = 'consoletest@test.com';

    /**
     * @var string The password of the test user.
     */
    protected string $password = 'password';

    /**
     * @var int The default number of requests to issue in parallel.
     */
    protected int $numberOfRequests = 10;

    /**
     * @var int The default number of amount of iterations to run the test. More iterations will increase the
     * certainty of catching a race condition if one exists.
     */
    protected int $numberOfIterations = 5;

    /**
     * @var Client The GuzzleHttp client which contains the cookie that represents logged-in user context to use for requests.
     */
    protected Client $httpClient;

    /**
     * @var PlayerService The player service of the test user.
     */
    protected PlayerService $playerService;

    /**
     * @var ObjectService The objectService.
     */
    protected ObjectService $objectService;

    /**
     * @var PlanetService The current planet service of the test user.
     */
    protected PlanetService $currentPlanetService;

    /**
     * @var PlanetService The second planet service of the test user.
     */
    protected PlanetService $secondPlanetService;

    /**
     * @throws BindingResolutionException
     * @throws ValidationException
     * @throws ConnectionException
     */
    protected function setup(): void
    {
        $playerServiceFactory = app()->make(PlayerServiceFactory::class);
        $planetServiceFactory = app()->make(PlanetServiceFactory::class);

        // Delete user if it already exists.
        $user = User::where('email', '=', $this->email)->first();
        if ($user) {
            $playerServiceFactory->make($user->id)->delete();
        }

        // Create a test user
        $creator = app()->make(CreateNewUser::class);
        $user = $creator->create([
            'name' => 'Test User race condition',
            'email' => $this->email,
            'password' => $this->password,
        ]);

        $this->info("Test user created with ID: {$user->id}");

        $this->playerService = $playerServiceFactory->make($user->id);
        $this->objectService = $this->playerService->planets->current()->objects;

        // Load current planet.
        $this->currentPlanetService = $this->playerService->planets->current();

        // If user does not have 2 planets already, create the second planet now.
        if ($this->playerService->planets->count() < 2) {
            // Try a random coordinate around the players current planet until we find a free one.
            $planet_created = false;
            $attempts = 0;
            while (!$planet_created && $attempts < 15) {
                // Start with galaxy/system and begin from position 1 up towards position 15.
                $attempts++;
                $coordinate = $this->currentPlanetService->getPlanetCoordinates();
                $coordinate = new Coordinate($coordinate->galaxy, $coordinate->system, $attempts);

                try {
                    $planetServiceFactory->createAdditionalForPlayer($this->playerService, $coordinate);
                    $planet_created = true;
                } catch (Exception $e) {
                    // Planet already exists, try next position.
                    continue;
                }
            }

            // Reload player object to include the new planet.
            $this->playerService->load($this->playerService->getId());
        }

        // Load second planet.
        $this->secondPlanetService = $this->playerService->planets->all()[1];

        $this->info("Planet 1: {$this->currentPlanetService->getPlanetId()} - {$this->currentPlanetService->getPlanetCoordinates()->asString()}");
        $this->info("Planet 2: {$this->secondPlanetService->getPlanetId()} - {$this->secondPlanetService->getPlanetCoordinates()->asString()}");

        // Login the user.
        $this->loginUser();
    }

    protected function genericAssertSetup(): void
    {
        $this->currentPlanetService->reloadPlanet();
    }

    /**
     * Login the test user and return the session cookie which can be used for further requests.
     *
     * @return void
     * @throws ConnectionException
     * @throws GuzzleException
     */
    protected function loginUser(): void
    {
        $this->info("Login as test user...");

        $this->httpClient = new Client(array(
            'cookies' => true
        ));

        $response = $this->httpClient->request('GET',"$this->appUrl/");
        // Parse the response body to find the CSRF token
        $csrfToken = null;

        if ($response->getStatusCode() == 200) {
            $html = $response->getBody();
            preg_match('/<meta name="csrf-token" content="([^"]+)"/', $html, $matches);
            if (isset($matches[1])) {
                $csrfToken = $matches[1];
                // Extract cookies from the response
                //$cookies = collect($response->cookies());
            }
        }

        // Login and get the session cookie
        $loginResponse = $this->httpClient->request('POST',"$this->appUrl/login", [
            'timeout' => 30,
            'form_params' => [
                '_token' => $csrfToken,
                'email' => $this->email,
                'password' => $this->password,
            ]
        ]);

        if (!$loginResponse->getStatusCode() == 200) {
            $this->error("Login failed. Status: " . $loginResponse->getStatusCode());
        }

        // Check if the login was successful by calling the overview page and checking if the player ID is present.
        $response = $this->httpClient->request('GET', "$this->appUrl/overview");
        if ($response->getStatusCode() != 200 || strpos($response->getBody()->getContents(), 'ogame-player-id') === false) {
            $this->error("Login failed, no 'ogame-player-id' metatag found while accessing /overview. Status: " . $response->getStatusCode());
        }
        else {
            $this->info("Login successful.");
        }
    }

    /**
     * Get an up-to-date CSRF token from the logged in home page HTML source.
     *
     * @return string
     * @throws GuzzleException
     */
    protected function getLoggedInCsrfToken(): string
    {
        $response = $this->httpClient->request('GET', "$this->appUrl/overview");
        $csrfToken = null;

        if ($response->getStatusCode() === 200) {
            $html = $response->getBody()->getContents();
            preg_match('/<meta name="csrf-token" content="([^"]+)"/', $html, $matches);
            if (isset($matches[1])) {
                $csrfToken = $matches[1];
            }
        }

        return $csrfToken;
    }

    protected function runParallelRequests($endpoint): void
    {
        $this->info("Running parallel requests...");

        // Store the start times for each request
        $timeLogs = [];

        // Use the session in parallel requests
        $appUrl = $this->appUrl;
        $numberOfRequests = $this->numberOfRequests;
        $absoluteEndpoint = "$appUrl/$endpoint";


        // Array to hold the promises
        $promises = [];

        // Perform the requests in parallel
        for ($i = 0; $i < $numberOfRequests; $i++) {
            $timeLogs[$i]['start'] = new DateTime(); // Capture the start time before making the request
            $promises[$i] = $this->httpClient->getAsync($absoluteEndpoint)->then(
                function ($response) use (&$timeLogs, $i) {
                    $timeLogs[$i]['end'] = new DateTime(); // Capture the end time after the request finishes
                    return $response;
                },
                function (RequestException $exception) use (&$timeLogs, $i) {
                    $timeLogs[$i]['end'] = new DateTime(); // Capture the end time even if the request fails
                    return $exception->getResponse();
                }
            );
        }

        // Wait for all requests to complete
        $responses = Promise\Utils::settle($promises)->wait();

        foreach ($responses as $i => $result) {
            try {
                $response = $result['value'];
                if ($response) {
                    $statusCode = $response->getStatusCode();
                    if ($response->getStatusCode() != 200 || strpos($response->getBody()->getContents(), 'ogame-player-id') === false) {
                        $this->error("Request failed, no 'ogame-player-id' metatag found while accessing URL in parallel loop. Status: " . $response->getStatusCode());
                    }
                    else {
                        $startTime = $timeLogs[$i]['start']->format('Y-m-d H:i:s.u');
                        $endTime = $timeLogs[$i]['end']->format('Y-m-d H:i:s.u');
                        $this->info("Request $i succeeded with status code $statusCode. (Started at: $startTime, Ended at: $endTime)");
                    }
                } else {
                    $this->error("Request $i failed without a valid response.");
                }
            } catch (Exception $e) {
                $this->error("Request $i failed with an unexpected error: " . $e->getMessage());
            }
        }
    }

}