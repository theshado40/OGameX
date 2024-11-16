<?php

namespace OGame\Http\Controllers;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use OGame\Http\Controllers\Abstracts\AbstractBuildingsController;
use OGame\Services\BuildingQueueService;
use OGame\Services\PlayerService;

class FacilitiesController extends AbstractBuildingsController
{
    /**
     * ResourcesController constructor.
     */
    public function __construct(BuildingQueueService $queue)
    {
        $this->route_view_index = 'facilities.index';
        parent::__construct($queue);
    }

    /**
     * Shows the facilities index page
     *
     * @param Request $request
     * @param PlayerService $player
     * @return View
     * @throws Exception
     */
    public function index(Request $request, PlayerService $player): View
    {
        $this->setBodyId('station');
        $this->planet = $player->planets->current();

        // Prepare custom properties.
        // Header filename objects are the building IDs that make up the header filename
        // to be used in the background image of the page header.
        if ($this->planet->isPlanet()) {
            $this->header_filename_objects = [14, 21, 31, 34];
        } else if ($this->planet->isMoon()) {
            $this->header_filename_objects = [];
        }

        $this->objects = [
            ['robot_factory', 'shipyard', 'research_lab', 'alliance_depot', 'missile_silo', 'nano_factory', 'terraformer', 'space_dock'],
        ];
        $this->view_name = 'ingame.facilities.index';

        return parent::index($request, $player);
    }

    /**
     * Handles the facilities page AJAX requests.
     *
     * @param Request $request
     * @param PlayerService $player
     * @return JsonResponse
     * @throws Exception
     */
    public function ajax(Request $request, PlayerService $player): JsonResponse
    {
        return $this->ajaxHandler($request, $player);
    }
}
