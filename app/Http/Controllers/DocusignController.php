<?php

namespace App\Http\Controllers;

use App\Models\Docusign;
use Illuminate\Http\Request;
use DocuSign\eSign\Configuration;
use DocuSign\eSign\Api\EnvelopesApi;
use DocuSign\eSign\Client\ApiClient;
use DocuSign\eSign\Model\Document;
use DocuSign\eSign\Model\EnvelopeDefinition;
use DocuSign\eSign\Model\Recipients;
use DocuSign\eSign\Model\RecipientViewRequest;
use DocuSign\eSign\Model\Signer;
use DocuSign\eSign\Model\SignHere;
use DocuSign\eSign\Model\Text;
use DocuSign\eSign\Model\Tabs;
use Illuminate\Support\Facades\Http;
use Exception;
use Session;

class DocusignController extends Controller
{
    private $config, $args, $signer_client_id = 1000;

    /**
     * Show the html page
     *
     * @return render
     */
    public function index()
    {
        return view('docusign');
    }

    /**
     * Connect your application to docusign
     *
     * @return url
     */
    public function connectDocusign()
    {
        try {
            $params = [
                'response_type' => 'code',
                'scope' => 'signature impersonation',
                'client_id' => env('DOCUSIGN_CLIENT_ID'),
                'state' => 'a39fh23hnf23',
                'redirect_uri' => route('docusign.callback'),
            ];

            $queryBuild = http_build_query($params);
            $url = "https://account-d.docusign.com/oauth/auth?";
            $botUrl = $url . $queryBuild;

            return redirect()->to($botUrl);
        } catch (Exception $e) {
            return redirect()->back()->with('error', 'Something Went wrong !');
        }
    }

    /**
     * This function called when you auth your application with docusign
     *
     * @return url
     */
    public function callback(Request $request)
    {
        $response = Http::asForm()
            ->withBasicAuth(env('DOCUSIGN_CLIENT_ID'), env('DOCUSIGN_CLIENT_SECRET'))
            ->post('https://account-d.docusign.com/oauth/token', [
                'grant_type'   => 'authorization_code',   // ✅ must be authorization_code
                'code'         => $request->get('code'),
                'redirect_uri' => route('docusign.callback'), // ✅ must match exactly
            ]);

        $result = $response->json();

        // ✅ debug nicely if token request failed
        if (!$response->successful()) {
            dd([
                'status' => $response->status(),
                'body'   => $result,
            ]);
        }

        if (!isset($result['access_token'])) {
            dd([
                'missing_access_token' => true,
                'body' => $result,
            ]);
        }

        $accessToken = $result['access_token'];

        $userInfo = Http::withToken($accessToken)
            ->get('https://account-d.docusign.com/oauth/userinfo')
            ->json();

        $defaultAccount = collect($userInfo['accounts'])->firstWhere('is_default', true)
            ?? $userInfo['accounts'][0];

        $request->session()->put('docusign_access_token', $accessToken);
        $request->session()->put('docusign_account_id', $defaultAccount['account_id']); // ✅ correct
        $request->session()->put('docusign_base_path', $defaultAccount['base_uri'] . '/restapi'); // ✅ correct

        return redirect()->route('docusign')->with('success', 'Docusign Successfully Connected');
    }

    /**
     * Write code on Method
     *
     * @return response()
     */
    public function signDocument()
    {
        if (!Session::has('docusign_access_token')) {
            return redirect()->route('connect.docusign')->with('error', 'Connect DocuSign first.');
        }

        try {
            $this->args = $this->getTemplateArgs();
            $args = $this->args;
            $envelope_args = $args["envelope_args"];

            /* Create the envelope request object */
            $envelope_definition = $this->makeEnvelopeFileObject($args["envelope_args"]);
            $envelope_api = $this->getEnvelopeApi();
            $api_client = new ApiClient($this->config);
            $envelope_api = new EnvelopesApi($api_client);
            $results = $envelope_api->createEnvelope($args['account_id'], $envelope_definition);
            $envelopeId = $results->getEnvelopeId();
            $authentication_method = 'None';
            $recipient_view_request = new RecipientViewRequest([
                'authentication_method' => $authentication_method,
                'client_user_id' => $envelope_args['signer_client_id'],
                'recipient_id' => '1',
                'return_url' => $envelope_args['ds_return_url'],
                'user_name' => 'Ayomide Ajayi', 'email' => 'ayomideseaz@gmail.com'
            ]);

            $results = $envelope_api->createRecipientView(
                $args['account_id'],
                $envelopeId,
                $recipient_view_request
            );

            return redirect()->to($results['url']);
        } catch (Exception $e) {

            dd($e->getMessage());
        }
    }

    private function makeEnvelopeFileObject($args)
    {
        $docsFilePath = public_path('doc/demo_pdf_new.pdf');
        $arrContextOptions = array(
            "ssl" => array(
                "verify_peer" => false,
                "verify_peer_name" => false,
            ),
        );
        $contentBytes = file_get_contents($docsFilePath, false, stream_context_create($arrContextOptions));

        /* Create the document model */
        $document = new Document([
            'document_base64' => base64_encode($contentBytes),
            'name' => 'The Tranquil Life Therapist Onboarding Agreement',
            'file_extension' => 'pdf',
            'document_id' => 1
        ]);

        //Pull signer details from backend (pass these in $args)
        $signerEmail = $args['signer_email'];
        $signerName  = $args['signer_name'];

        /* Create the signer recipient model */
        $signer = new Signer([
            'email' => $signerEmail,
            'name' => $signerName,
            'recipient_id' => '1',
            'routing_order' => '1',
            'client_user_id' => $args['signer_client_id']
        ]);

        // REQUIRED NAME FIELD
        $fullName = new \DocuSign\eSign\Model\Text([
            'anchor_string' => '[[TL_FULL_NAME]]',
            'anchor_units' => 'pixels',
            'anchor_x_offset' => '0',
            'anchor_y_offset' => '-5',
            'required' => 'true',
            'tab_label' => 'Full Name',
        ]);

        // REQUIRED ADDRESS FIELD
        $address = new \DocuSign\eSign\Model\Text([
            'anchor_string' => '[[TL_ADDRESS]]',
            'anchor_units' => 'pixels',
            'anchor_x_offset' => '0',
            'anchor_y_offset' => '-5',
            'required' => 'true',
            'tab_label' => 'Address',
        ]);

        /* 
        - Create a signHere tab (field on the document)
        - Auto-place signature at the last page marker 
        */
        $signHere = new SignHere([
            'anchor_string' => '[[TL_SIGN_HERE]]', // <-- put this in PDF on last page
            'anchor_units' => 'pixels',
            'anchor_x_offset' => '20',
            'anchor_y_offset' => '10',
            'required' => 'true',
        ]);

        $signer->setTabs(new Tabs([
            'sign_here_tabs' => [$signHere],
            'text_tabs' => [$fullName, $address]
        ]));

        $envelopeDefinition = new EnvelopeDefinition([
            'email_subject' => "Tranquil Life Therapist Onboarding Agreement",
            'documents' => [$document],
            'recipients' => new Recipients(['signers' => [$signer]]),
            'status' => "sent",
        ]);

        return $envelopeDefinition;
    }

    /**
     * Write code on Method
     *
     * @return response()
     */
    public function getEnvelopeApi(): EnvelopesApi
    {
        $this->config = new Configuration();
        $this->config->setHost($this->args['base_path']);
        $this->config->addDefaultHeader('Authorization', 'Bearer ' . $this->args['ds_access_token']);
        $this->apiClient = new ApiClient($this->config);

        return new EnvelopesApi($this->apiClient);
    }

    /**
     * Write code on Method
     *
     * @return response()
     */
    private function getTemplateArgs()
    {
        $args = [
            'account_id' => Session::get('docusign_account_id'),
            'base_path' => Session::get('docusign_base_path'),
            'ds_access_token' => Session::get('docusign_access_token'),

            // 'account_id' => env('DOCUSIGN_ACCOUNT_ID'),
            // 'base_path' => env('DOCUSIGN_BASE_URL'),
            // 'ds_access_token' => Session::get('docusign_auth_code'),
            'envelope_args' => [
                'signer_client_id' => $this->signer_client_id,
                'signer_name' => 'Ayomide Ajayi',
                'signer_email' => 'ayomideseaz@gmail.com',
                'ds_return_url' => route('docusign'),
            ]
        ];

        return $args;
    }
}
