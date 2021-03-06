<?php
/**
 * Created by PhpStorm.
 * User: liuxiaofeng
 * Date: 2019-03-16
 * Time: 17:20
 */

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\Activities\SaveRequest;
use App\Http\Requests\Api\FormRequest;
use App\Models\Activity;
use App\Models\ActivityParticipant;
use App\Models\Bill;
use App\Models\User;
use App\Transformers\ActivityTransformer;
use App\Transformers\UserTransformer;
use DB;
use Ramsey\Uuid\Uuid;

class ActivitiesController extends Controller
{

    public function save(SaveRequest $request, Activity $activity = null) {
        $created_activity = false;
        if(empty($activity)){
            $created_activity = true;
            $activity = new Activity();
            $activity->user_id = $this->user()->id;
            $activity->wx_a_code = '';
        }else{
            if(!$this->user()->isAuthorOf($activity)){
                return $this->response->errorUnauthorized();
            }
        }
        $activity->name = $request->name;

        DB::transaction(function() use ($activity, $created_activity) {

            $activity->save();
            if(empty($activity->wx_a_code)){
                $miniProgram = \EasyWeChat::miniProgram();
                $response = $miniProgram->app_code->getUnlimit('activity_id:'.$activity->id);
                // 保存小程序码到文件
                if ($response instanceof \EasyWeChat\Kernel\Http\StreamResponse) {
                    $filename = $response->save(\Storage::disk('public')->path(''), Uuid::uuid1()->toString());
                    $activity->wx_a_code = '/'.$filename;

                    $activity->save();
                }
            }


            if($created_activity) {
                ActivityParticipant::query()->create(
                    ['activity_id' => $activity->id, 'user_id' => $this->user()->id]
                );
            }
        });

        return $this->response->item($activity, (new ActivityTransformer()))->setStatusCode(201);
    }

    public function show(Activity $activity) {
        $user = $this->user();
        if(!ActivityParticipant::query()->where('user_id', $user->id)->where('activity_id', $activity->id)->exists()){
            return $this->response->errorNotFound();
        }

        return $this->response->item($activity, new ActivityTransformer());

    }

    public function myActivities() {

        $user = $this->user();
        $my_activities = $this->user()
            ->participatedActivities()
            ->orderBy('id', 'desc')
            ->get();

        $my_activities_id = $my_activities->pluck('id');

        $my_bills = Bill::query()->whereIn('activity_id', $my_activities_id)
            ->select('id', 'activity_id','user_id')
            ->where(function($query) {
                $query
                    ->where('user_id', $this->user->id)
                    ->orWhereHas('participants', function($query){
                        $query->where('user_id', $this->user->id);
                    });
            })
            ->with(['participants' => function($query) use ($user) {

            }])
            ->get();

        $grouped = $my_bills->groupBy('activity_id');

        $my_activities->each(function($value) use ($grouped){
            $value->split_sum = 0.00;
            $value->unpaid_sum = 0.00;

            if(isset($grouped[$value->id])) {
                $value->all_unpaid_sum = 0.00;
                $collection = collect($grouped[$value->id]);
                $participants_of_me = $collection->pluck('participants')->collapse()->filter(function($participant) {
                    return $participant->user_id === $this->user->id;
                });

                $value->split_sum = $participants_of_me->sum(function($item){
                    return $item->split_money * 100;
                });
                $value->split_sum = bcdiv($value->split_sum, 100, 2);
                $value->unpaid_sum = $participants_of_me->sum(function($item){
                    return $item->paid ? 0.00 : $item->split_money * 100;
                });
                $value->unpaid_sum = bcdiv($value->unpaid_sum, 100, 2);


                $value->all_unpaid_sum = $collection->filter(function($bill) {
                    return $bill->user_id === $this->user->id;
                })->pluck('participants')->collapse()->filter(function($participant) {
                    return $participant->user_id !== $this->user->id && !$participant->paid;
                })->sum(function($item) {
                    return $item->split_money * 100;
                });

                $value->all_unpaid_sum = bcdiv($value->all_unpaid_sum, 100, 2);
            }
        });


        return $this->response->collection($my_activities, new ActivityTransformer());
    }


    public function participate(Activity $activity) {
        $activity->participatedUsers()->syncWithoutDetaching([$this->user()->id]);

        return $this->response->noContent();
    }


    public function participants(Activity $activity) {
        return $this->response->collection($activity->participatedUsers()->get(), new UserTransformer());
    }


    public function quit(Activity $activity) {
        DB::transaction(function() use ($activity){
            $activity->participatedUsers()->detach([$this->user()->id]);
            if($this->user()->isAuthorOf($activity)){
                $activity->participatedUsers()->detach();
                $activity->delete();
                foreach ($activity->bills as $bill){
                    $bill->participants()->delete();
                    $bill->items()->delete();
                    $bill->delete();
                }
            }
        });

        return $this->response->noContent();
    }

    public function removeParticipants(FormRequest $request, Activity $activity) {
        if(!$this->user->isAuthorOf($activity)) {
            return $this->response->errorUnauthorized();
        }

        $activity->participatedUsers()->detach($request->user_ids);

        return $this->response->collection($activity->participatedUsers()->get(), new UserTransformer());
    }
}
