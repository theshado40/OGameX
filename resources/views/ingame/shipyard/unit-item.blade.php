@props(['building'])
@php /** @var OGame\ViewModels\UnitViewModel $building */ @endphp

<li class="technology {{ $building->object->class_name }} hasDetails tooltip hideTooltipOnMouseenter js_hideTipOnMobile ipiHintable tpd-hideOnClickOutside"
    data-technology="{{ $building->object->id }}"
    data-is-spaceprovider=""
    aria-label="{{ $building->object->title }}"
    data-ipi-hint="ipiTechnology{{ $building->object->class_name }}"
    @if ($building->currently_building)
        data-status="active"
    data-is-spaceprovider=""
    data-progress="26"
    data-start="1713521207"
    data-end="1713604880"
    data-total="61608"
    title="{{ $building->object->title }}<br/>@lang('Under construction')"
    @elseif (!$building->requirements_met)
        data-status="off"
    title="{{ $building->object->title }}<br/>@lang('Requirements are not met!')"
    @elseif (!$building->enough_resources)
        data-status="disabled"
    title="{{ $building->object->title }}<br/>@lang('Not enough resources!')"
    @else
        data-status="on"
    title="{{ $building->object->title }}"
        @endif
>

                        <span class="icon sprite sprite_small small {{ $building->object->class_name }}">
                            @if ($building->currently_building)
                                <span class="targetamount" data-value="54" data-bonus="0">
                                    {{ $building->amount + $building->currently_building_amount }}
                                </span>
                                <div class="cooldownBackground"></div>
                                <time-counter><time class="countdown buildingCountdown" id="countdownbuildingDetails" data-segments="2">...</time></time-counter>
                            @endif
                            <span class="amount"
                              data-value="{{ $building->amount }}"
                              data-bonus="0">
                                <span class="stockAmount">{{ $building->amount }}</span>
                                <span class="bonus"></span>
                            </span>
                        </span>