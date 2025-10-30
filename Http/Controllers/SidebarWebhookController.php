<?php

namespace Modules\SidebarWebhook\Http\Controllers;

use App\Mailbox;
use App\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class SidebarWebhookController extends Controller
{
    /**
     * Edit ratings.
     * @return Response
     */
    public function mailboxSettings($id)
    {
        $mailbox = Mailbox::findOrFail($id);

        return view('sidebarwebhook::mailbox_settings', [
            'settings' => [
                'sidebarwebhook.url' => \Option::get('sidebarwebhook.url')[(string)$id] ?? '',
                'sidebarwebhook.secret' => \Option::get('sidebarwebhook.secret')[(string)$id] ?? '',
            ],
            'mailbox' => $mailbox
        ]);
    }

    public function mailboxSettingsSave($id, Request $request)
    {
        $mailbox = Mailbox::findOrFail($id);

        $settings = $request->settings ?: [];

        $urls = \Option::get('sidebarwebhook.url') ?: [];
        $secrets = \Option::get('sidebarwebhook.secret') ?: [];

        $urls[(string)$id] = $settings['sidebarwebhook.url'] ?? '';
        $secrets[(string)$id] = $settings['sidebarwebhook.secret'] ?? '';

        \Option::set('sidebarwebhook.url', $urls);
        \Option::set('sidebarwebhook.secret', $secrets);

        \Session::flash('flash_success_floating', __('Settings updated'));

        return redirect()->route('mailboxes.sidebarwebhook', ['id' => $id]);
    }

    /**
     * Ajax controller.
     */
    public function ajax(Request $request)
    {
        $response = [
            'status' => 'error',
            'msg'    => '', // this is error message
        ];

        switch ($request->action) {

            case 'loadSidebar':
                // mailbox_id and customer_id are required.
                if (!$request->mailbox_id || !$request->conversation_id) {
                    $response['msg'] = 'Missing required parameters';
                    break;
                }

                try {
                    $mailbox = Mailbox::findOrFail($request->mailbox_id);
                    $conversation = Conversation::findOrFail($request->conversation_id);
                    $customer = $conversation->customer;
                } catch (\Exception $e) {
                    $response['msg'] = 'Invalid mailbox or customer';
                    break;
                }

                $url = \Option::get('sidebarwebhook.url')[(string)$mailbox->id] ?? '';
                $secret = \Option::get('sidebarwebhook.secret')[(string)$mailbox->id] ?? '';
                if (!$url) {
                    $response['msg'] = 'Webhook URL is not set';
                    break;
                }

				$payload = json_encode([
					'ticket'   => [
						'id'      => $conversation->id,
						'number'  => $conversation->number,
						'subject' => $conversation->getSubject(),
					],
					'customer' => [
						'id'     => $customer->id,
						'fname'  => $customer->first_name,
						'lname'  => $customer->last_name,
						'email'  => $customer->getMainEmail(),
						'emails' => $customer->emails->pluck('email')->toArray(),
					],
					'user'     => [
						'fname'        => \Auth::user()->first_name,
						'lname'        => \Auth::user()->last_name,
						'id'           => \Auth::user()->id,
						'role'         => \Auth::user()->role,
						'convRedirect' => 0,
					],
					'mailbox'  => [
						'id'    => str_slug($mailbox->name, ''),
						'email' => $mailbox->email,
					],
				]);

				try {
                    $signature = base64_encode(hash_hmac('sha1', $payload, $secret, true));
					$client = new \GuzzleHttp\Client();
					$result = $client->post($url, [
						'headers' => [
							'Content-Type' => 'application/json',
							'Accept'       => 'text/html',
                            'x-Helpscout-Signature' => $signature,
						],
						'body'    => $payload,
					]);
                    $response = json_decode($result->getBody()->getContents(), true);
                    $response['status'] = 'success';
                } catch (\Exception $e) {
                    $response['msg'] = 'Webhook error: ' . $e->getMessage();
                    break;
                }

                break;

            default:
                $response['msg'] = 'Unknown action';
                break;
        }

        if ($response['status'] == 'error' && empty($response['msg'])) {
            $response['msg'] = 'Unknown error occured';
        }

        return \Response::json($response);
    }
}
