<?php

namespace Tests\Controllers\UI;

use Illuminate\Foundation\Testing\DatabaseTransactions;

class ChannelControllerTest extends \TestCase
{
    use DatabaseTransactions;

    /**
     * @var string
     */
    protected $apiUrl = '/api/ui/services/1/channels';

    /**
     * @test
     */
    public function testFailOnDestroyWithWrongId()
    {
        $authUser = \App\Models\User::where('name', 'adminuser')->first();
        $this->actingAs($authUser, 'api');

        $this->doRequest('delete', '/api/ui/services/2/channels/1');
        $this->seeStatusCode(422);
        $content = $this->decodeResponseJson();
        $this->assertEquals('Channel model is not found with given identifier', $content['error']['message']);
    }

    /**
     * Data provider for requests
     *
     * Datastructure:
     * ['userRole', verb', 'uri', 'data', 'responce status'] // Resource controller action
     *
     * @return array
     */
    public function requestTypeProvider()
    {
        return [
            //  unauth user
            ['unauth', 'get', '', [], '401'], // getFromService
            ['unauth', 'post', '', [], '401'], // store
            ['unauth', 'get', '1', [], '405'], // show
            ['unauth', 'put', '1', [], '405'], // update (full)
            ['unauth', 'patch', '1', [], '405'], // update (partial)
            ['unauth', 'delete', '1', [], '401'], // destroy
            // admin user
            ['admin', 'get', '', [], '200'], // getFromService
            ['admin', 'post', '', ['label' => 'test', 'service_id' => 1], '200'], // store
            ['admin', 'get', '1', [], '405'], // show
            ['admin', 'put', '1', [], '405'], // update (full)
            ['admin', 'patch', '1', [], '405'], // update (partial)
            ['admin', 'delete', '1', [], '200'], // destroy
            // owner user
            ['owner', 'get', '', [], '200'], // getFromService
            ['owner', 'post', '', ['label' => 'test', 'service_id' => 1], '200'], // store
            ['owner', 'get', '1', [], '405'], // show
            ['owner', 'put', '1', [], '405'], // update (full)
            ['owner', 'patch', '1', [], '405'], // update (partial)
            ['owner', 'delete', '1', [], '200'], // destroy
            // member user
            ['member', 'get', '', [], '200'], // getFromService
            ['member', 'post', '', ['label' => 'test', 'service_id' => 1], '200'], // store
            ['member', 'get', '1', [], '405'], // show
            ['member', 'put', '1', [], '405'], // update (full)
            ['member', 'patch', '1', [], '405'], // update (partial)
            ['member', 'delete', '1', [], '200'], // destroy
        ];
    }

    /**
     * @test
     * @dataProvider requestTypeProvider
     */
    public function testUIChannelRequests($userRole, $verb, $pathArg, $data, $statusCode)
    {
        $this->requestsByUserWithRoleAndCheckStatusCode($userRole, $verb, $pathArg, $data, $statusCode);
    }

    /**
     * assemble the path on the given params
     */
    protected function assemblePath($params)
    {
        return $this->apiUrl . '/' . $params;
    }
}
