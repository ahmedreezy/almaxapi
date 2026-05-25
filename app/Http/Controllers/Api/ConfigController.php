<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FreeOdd2;
use App\Models\VipConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/config/free-odd2     — public
 * PUT /api/config/free-odd2     — admin
 * GET /api/config/vip-config    — public
 * PUT /api/config/vip-config    — admin
 */
class ConfigController extends Controller
{
    use HandlesFileUploads;

    public function getFreeOdd2(): JsonResponse
    {
        return response()->json(FreeOdd2::instance());
    }

    public function updateFreeOdd2(Request $request): JsonResponse
    {
        $data = $request->validate([
            'teamA'       => ['sometimes', 'string', 'max:200'],
            'teamB'       => ['sometimes', 'string', 'max:200'],
            'pick'        => ['sometimes', 'string', 'max:200'],
            'odd'         => ['sometimes', 'string', 'max:20'],
            'time'        => ['sometimes', 'string', 'max:20'],
            'competition' => ['sometimes', 'string', 'max:200'],
            'caption'     => ['sometimes', 'nullable', 'string'],
            'image'       => ['sometimes', 'nullable', 'file', 'mimes:jpeg,png,webp', 'max:5120'],
        ]);

        $odd = FreeOdd2::instance();

        if ($request->hasFile('image')) {
            $this->deleteUpload($odd->image_url);
            $data['image_url'] = $this->storeUpload($request->file('image'), 'config');
        }

        $odd->update([
            'team_a'      => $data['teamA']       ?? $odd->team_a,
            'team_b'      => $data['teamB']       ?? $odd->team_b,
            'pick'        => $data['pick']         ?? $odd->pick,
            'odd'         => $data['odd']          ?? $odd->odd,
            'time'        => $data['time']         ?? $odd->time,
            'competition' => $data['competition']  ?? $odd->competition,
            'caption'     => $data['caption']      ?? $odd->caption,
            'image_url'   => $data['image_url']    ?? $odd->image_url,
        ]);

        return response()->json($odd->fresh());
    }

    public function getVipConfig(): JsonResponse
    {
        return response()->json(VipConfig::allAsMap());
    }

    public function updateVipConfig(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key'   => ['required', 'string', 'max:100'],
            'value' => ['required', 'string'],
        ]);

        // Whitelist of updatable keys — prevents arbitrary key injection
        $allowed = [
            'daily_price', 'weekly_price',
            'odds_1_5_weekly_price',
            'odds_2_daily_price', 'odds_2_weekly_price',
            'odds_5_daily_price', 'odds_5_weekly_price',
            'mtn_number', 'airtel_number', 'whatsapp_link',
            'current_betslip_link', 'current_betslip_code',
            'ad_video_url',
            'betslip_link_1_5_weekly', 'betslip_code_1_5_weekly',
            'betslip_link_2_daily',    'betslip_code_2_daily',
            'betslip_link_2_weekly',   'betslip_code_2_weekly',
            'betslip_link_5_daily',    'betslip_code_5_daily',
            'betslip_link_5_weekly',   'betslip_code_5_weekly',
        ];

        if (! in_array($data['key'], $allowed, true)) {
            return response()->json(['error' => "Key '{$data['key']}' is not a permitted config key."], 422);
        }

        VipConfig::setValue($data['key'], $data['value']);

        return response()->json(['key' => $data['key'], 'value' => $data['value']]);
    }
}
