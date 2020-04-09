<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Security\Http\Tests\Authenticator;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\User\User;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Authenticator\JsonLoginAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\HttpUtils;

class JsonLoginAuthenticatorTest extends TestCase
{
    private $userProvider;
    /** @var JsonLoginAuthenticator */
    private $authenticator;

    protected function setUp(): void
    {
        $this->userProvider = $this->createMock(UserProviderInterface::class);
    }

    /**
     * @dataProvider provideSupportData
     */
    public function testSupport($request)
    {
        $this->setUpAuthenticator();

        $this->assertTrue($this->authenticator->supports($request));
    }

    public function provideSupportData()
    {
        yield [new Request([], [], [], [], [], ['HTTP_CONTENT_TYPE' => 'application/json'], '{"username": "dunglas", "password": "foo"}')];

        $request = new Request([], [], [], [], [], [], '{"username": "dunglas", "password": "foo"}');
        $request->setRequestFormat('json-ld');
        yield [$request];
    }

    /**
     * @dataProvider provideSupportsWithCheckPathData
     */
    public function testSupportsWithCheckPath($request, $result)
    {
        $this->setUpAuthenticator(['check_path' => '/api/login']);

        $this->assertSame($result, $this->authenticator->supports($request));
    }

    public function provideSupportsWithCheckPathData()
    {
        yield [Request::create('/api/login', 'GET', [], [], [], ['HTTP_CONTENT_TYPE' => 'application/json']), true];
        yield [Request::create('/login', 'GET', [], [], [], ['HTTP_CONTENT_TYPE' => 'application/json']), false];
    }

    public function testAuthenticate()
    {
        $this->setUpAuthenticator();

        $this->userProvider->expects($this->once())->method('loadUserByUsername')->with('dunglas')->willReturn(new User('dunglas', 'pa$$'));

        $request = new Request([], [], [], [], [], ['HTTP_CONTENT_TYPE' => 'application/json'], '{"username": "dunglas", "password": "foo"}');
        $passport = $this->authenticator->authenticate($request);
        $this->assertEquals('foo', $passport->getBadge(PasswordCredentials::class)->getPassword());
    }

    public function testAuthenticateWithCustomPath()
    {
        $this->setUpAuthenticator([
            'username_path' => 'authentication.username',
            'password_path' => 'authentication.password',
        ]);

        $this->userProvider->expects($this->once())->method('loadUserByUsername')->with('dunglas')->willReturn(new User('dunglas', 'pa$$'));

        $request = new Request([], [], [], [], [], ['HTTP_CONTENT_TYPE' => 'application/json'], '{"authentication": {"username": "dunglas", "password": "foo"}}');
        $passport = $this->authenticator->authenticate($request);
        $this->assertEquals('foo', $passport->getBadge(PasswordCredentials::class)->getPassword());
    }

    /**
     * @dataProvider provideInvalidAuthenticateData
     */
    public function testAuthenticateInvalid($request, $errorMessage, $exceptionType = BadRequestHttpException::class)
    {
        $this->expectException($exceptionType);
        $this->expectExceptionMessage($errorMessage);

        $this->setUpAuthenticator();

        $this->authenticator->authenticate($request);
    }

    public function provideInvalidAuthenticateData()
    {
        $request = new Request([], [], [], [], [], ['HTTP_CONTENT_TYPE' => 'application/json']);
        yield [$request, 'Invalid JSON.'];

        $request = new Request([], [], [], [], [], ['HTTP_CONTENT_TYPE' => 'application/json'], '{"usr": "dunglas", "password": "foo"}');
        yield [$request, 'The key "username" must be provided'];

        $request = new Request([], [], [], [], [], ['HTTP_CONTENT_TYPE' => 'application/json'], '{"username": "dunglas", "pass": "foo"}');
        yield [$request, 'The key "password" must be provided'];

        $request = new Request([], [], [], [], [], ['HTTP_CONTENT_TYPE' => 'application/json'], '{"username": 1, "password": "foo"}');
        yield [$request, 'The key "username" must be a string.'];

        $request = new Request([], [], [], [], [], ['HTTP_CONTENT_TYPE' => 'application/json'], '{"username": "dunglas", "password": 1}');
        yield [$request, 'The key "password" must be a string.'];

        $username = str_repeat('x', Security::MAX_USERNAME_LENGTH + 1);
        $request = new Request([], [], [], [], [], ['HTTP_CONTENT_TYPE' => 'application/json'], sprintf('{"username": "%s", "password": 1}', $username));
        yield [$request, 'Invalid username.', BadCredentialsException::class];
    }

    private function setUpAuthenticator(array $options = [])
    {
        $this->authenticator = new JsonLoginAuthenticator(new HttpUtils(), $this->userProvider, null, null, $options);
    }
}
