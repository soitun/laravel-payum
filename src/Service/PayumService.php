<?php

namespace Recca0120\LaravelPayum\Service;

use Payum\Core\Payum;
use Illuminate\Http\Request;
use Payum\Core\Request\Sync;
use Payum\Core\Request\Cancel;
use Payum\Core\Request\Notify;
use Payum\Core\Request\Payout;
use Payum\Core\Request\Refund;
use Payum\Core\Request\Capture;
use Payum\Core\Request\Convert;
use Payum\Core\Request\Authorize;
use Payum\Core\Reply\ReplyInterface;
use Payum\Core\Request\GetHumanStatus;
use Payum\Core\Model\Payment as PayumPayment;
use Illuminate\Contracts\Routing\ResponseFactory;
use Recca0120\LaravelPayum\Model\Payment as EloquentPayment;
use Payum\Core\Bridge\Symfony\ReplyToSymfonyResponseConverter;

class PayumService
{
    /**
     * $payum.
     *
     * @var \Payum\Core\Payum
     */
    protected $payum;

    /**
     * $payum.
     *
     * @var \Illuminate\Http\Request
     */
    protected $request;

    /**
     * $responseFactory.
     *
     * @var \Illuminate\Contracts\Routing\ResponseFactory
     */
    protected $responseFactory;

    /**
     * $converter.
     *
     * @var \Payum\Core\Bridge\Symfony\ReplyToSymfonyResponseConverter
     */
    protected $converter;

    /**
     * $payumTokenId.
     *
     * @var string
     */
    protected $payumTokenId = 'payum_token';

    /**
     * __construct.
     *
     * @param \Payum\Core\Payum $payum
     * @param \Illuminate\Http\Request $request
     * @param \Illuminate\Contracts\Routing\ResponseFactory $responseFactory
     * @param \Payum\Core\Bridge\Symfony\ReplyToSymfonyResponseConverter $converter
     */
    public function __construct(
        Payum $payum,
        Request $request,
        ResponseFactory $responseFactory,
        ReplyToSymfonyResponseConverter $converter
    ) {
        $this->payum = $payum;
        $this->request = $request;
        $this->responseFactory = $responseFactory;
        $this->converter = $converter;
    }

    /**
     * getPayum.
     *
     * @return \Payum\Core\Payum
     */
    public function getPayum()
    {
        return $this->payum;
    }

    /**
     * getGateway.
     *
     * @param string $gatewayName
     * @return \Payum\Core\GatewayInterface
     */
    public function getGateway($gatewayName)
    {
        return $this->getPayum()->getGateway($gatewayName);
    }

    /**
     * request.
     *
     * @param string $gatewayName
     * @param callable $closure
     * @param string $afterPath
     * @param array $afterParameters
     * @param string $tokenType
     * @return \Illuminate\Http\RedirectResponse
     */
    public function request($gatewayName, callable $closure, $afterPath = 'payment.done', array $afterParameters = [], $tokenType = 'Capture')
    {
        $storage = $this->getStorage();
        $payment = $storage->create();
        $closure($payment, $gatewayName, $storage, $this->getPayum(), $this->request);
        $storage->update($payment);
        $tokenFactory = $this->getPayum()->getTokenFactory();
        $method = 'create'.ucfirst($tokenType).'Token';
        $token = call_user_func_array([$tokenFactory, $method], [
            $gatewayName,
            $payment,
            $afterPath,
            $afterParameters,
        ]);

        return $this->responseFactory->redirectTo($token->getTargetUrl());
    }

    /**
     * prepare.
     *
     * @param string $gatewayName
     * @param callable $closure
     * @param string $afterPath
     * @param array $afterParameters
     * @param string $tokenType
     * @return \Illuminate\Http\RedirectResponse
     */
    public function prepare($gatewayName, callable $closure, $afterPath = 'payment.done', array $afterParameters = [], $tokenType = 'Capture')
    {
        return $this->request($gatewayName, $closure, $afterPath, $afterParameters, $tokenType);
    }

    /**
     * capture.
     *
     * @param string $gatewayName
     * @param callable $closure
     * @param string $afterPath
     * @param array $afterParameters
     * @return \Illuminate\Http\RedirectResponse
     */
    public function capture($gatewayName, callable $closure, $afterPath = 'payment.done', array $afterParameters = [])
    {
        return $this->request($gatewayName, $closure, $afterPath, $afterParameters, 'Capture');
    }

    /**
     * authorize.
     *
     * @param string $gatewayName
     * @param callable $closure
     * @param string $afterPath
     * @param array $afterParameters
     * @return \Illuminate\Http\RedirectResponse
     */
    public function authorize($gatewayName, callable $closure, $afterPath = 'payment.done', array $afterParameters = [])
    {
        return $this->request($gatewayName, $closure, $afterPath, $afterParameters, 'Authorize');
    }

    /**
     * refund.
     *
     * @param string $gatewayName
     * @param callable $closure
     * @param string $afterPath
     * @param array $afterParameters
     * @return \Illuminate\Http\RedirectResponse
     */
    public function refund($gatewayName, callable $closure, $afterPath = 'payment.done', array $afterParameters = [])
    {
        return $this->request($gatewayName, $closure, $afterPath, $afterParameters, 'Refund');
    }

    /**
     * cancel.
     *
     * @param string $gatewayName
     * @param callable $closure
     * @param string $afterPath
     * @param array $afterParameters
     * @return \Illuminate\Http\RedirectResponse
     */
    public function cancel($gatewayName, callable $closure, $afterPath = 'payment.done', array $afterParameters = [])
    {
        return $this->request($gatewayName, $closure, $afterPath, $afterParameters, 'Cancel');
    }

    /**
     * payout.
     *
     * @param string $gatewayName
     * @param callable $closure
     * @param string $afterPath
     * @param array $afterParameters
     * @return \Illuminate\Http\RedirectResponse
     */
    public function payout($gatewayName, callable $closure, $afterPath = 'payment.done', array $afterParameters = [])
    {
        return $this->request($gatewayName, $closure, $afterPath, $afterParameters, 'Payout');
    }

    /**
     * send.
     *
     * @param string $payumToken
     * @param callable $closure
     * @return \Illuminate\Http\Response|\Symfony\Component\HttpFoundation\Response
     */
    public function receive($payumToken, callable $closure)
    {
        $payumToken = $this->getPayumToken($payumToken);
        $request = $this->request->duplicate();
        $request->merge([
            $this->payumTokenId => $payumToken,
        ]);
        $httpRequestVerifier = $this->getPayum()->getHttpRequestVerifier();
        $token = $httpRequestVerifier->verify($request);
        $gateway = $this->getGateway($token->getGatewayName());

        try {
            return $closure($gateway, $token, $httpRequestVerifier);
        } catch (ReplyInterface $reply) {
            $this->storePayumToken($payumToken);

            return $this->converter->convert($reply);
        }
    }

    /**
     * receiveAuthorize.
     *
     * @param string $payumToken
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function receiveAuthorize($payumToken)
    {
        return $this->receive($payumToken, function ($gateway, $token, $httpRequestVerifier) {
            $gateway->execute(new Authorize($token));
            $httpRequestVerifier->invalidate($token);

            return $this->responseFactory->redirectTo($token->getAfterUrl());
        });
    }

    /**
     * receiveCapture.
     *
     * @param string $payumToken
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function receiveCapture($payumToken = null)
    {
        return $this->receive($payumToken, function ($gateway, $token, $httpRequestVerifier) {
            $gateway->execute(new Capture($token));
            $httpRequestVerifier->invalidate($token);

            return $this->responseFactory->redirectTo($token->getAfterUrl());
        });
    }

    /**
     * receiveNotify.
     *
     * @param string $payumToken
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function receiveNotify($payumToken)
    {
        return $this->receive($payumToken, function ($gateway, $token, $httpRequestVerifier) {
            $gateway->execute(new Notify($token));

            return $this->responseFactory->make(null, 204);
        });
    }

    /**
     * receiveNotifyUnsafe.
     *
     * @param string $gatewayName
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function receiveNotifyUnsafe($gatewayName)
    {
        try {
            $gateway = $this->getPayum()->getGateway($gatewayName);
            $gateway->execute(new Notify(null));

            return $this->responseFactory->make(null, 204);
        } catch (ReplyInterface $reply) {
            return $this->converter->convert($reply);
        }
    }

    /**
     * receivePayout.
     *
     * @param string $payumToken
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function receivePayout($payumToken)
    {
        return $this->receive($payumToken, function ($gateway, $token, $httpRequestVerifier) {
            $gateway->execute(new Payout($token));
            $httpRequestVerifier->invalidate($token);

            return $this->responseFactory->redirectTo($token->getAfterUrl());
        });
    }

    /**
     * receiveCancel.
     *
     * @param string $payumToken
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function receiveCancel($payumToken)
    {
        return $this->receive($payumToken, function ($gateway, $token, $httpRequestVerifier) {
            $gateway->execute(new Cancel($token));
            $httpRequestVerifier->invalidate($token);
            $afterUrl = $token->getAfterUrl();
            if (empty($afterUrl) === false) {
                return $this->responseFactory->redirectTo($afterUrl);
            }

            return $this->responseFactory->make(null, 204);
        });
    }

    /**
     * receiveRefund.
     *
     * @param string $payumToken
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function receiveRefund($payumToken)
    {
        return $this->receive($payumToken, function ($gateway, $token, $httpRequestVerifier) {
            $gateway->execute(new Refund($token));
            $httpRequestVerifier->invalidate($token);
            $afterUrl = $token->getAfterUrl();
            if (empty($afterUrl) === false) {
                return $this->responseFactory->redirectTo($afterUrl);
            }

            return $this->responseFactory->make(null, 204);
        });
    }

    /**
     * receiveSync.
     *
     * @param string $payumToken
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function receiveSync($payumToken)
    {
        return $this->receive($payumToken, function ($gateway, $token, $httpRequestVerifier) {
            $gateway->execute(new Sync($token));
            $httpRequestVerifier->invalidate($token);

            return $this->responseFactory->redirectTo($token->getAfterUrl());
        });
    }

    /**
     * receiveDone.
     *
     * @param string $payumToken
     * @param callable $closure
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function receiveDone($payumToken, callable $closure)
    {
        return $this->done($payumToken, $closure);
    }

    /**
     * done.
     *
     * @param string $payumToken
     * @param callable $closure
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function done($payumToken, callable $closure)
    {
        return $this->receive($payumToken, function ($gateway, $token, $httpRequestVerifier) use ($closure) {
            $gateway->execute($status = new GetHumanStatus($token));
            $payment = $status->getFirstModel();

            return $closure($status, $payment, $gateway, $token, $httpRequestVerifier);
        });
    }

    /**
     * sync.
     *
     * @param string $gatewayName
     * @param callable $closure
     */
    public function sync($gatewayName, callable $closure)
    {
        $gateway = $this->getGateway($gatewayName);
        $storage = $this->getStorage();
        $payment = $storage->create();
        $closure($payment, $gatewayName, $storage, $this->getPayum());

        $request = new Sync($payment);
        $convert = new Convert($payment, 'array', $request->getToken());
        $gateway->execute($convert);
        $payment->setDetails($convert->getResult());
        $gateway->execute($request);

        return $request->getModel();
    }

    /**
     * getSessionFromRequest.
     *
     * @return \Symfony\Component\HttpFoundation\Session\SessionInterface|null
     */
    protected function getSessionFromRequest()
    {
        $session = $this->request->session();
        if ($session->isStarted() === false) {
            $session->start();
        }

        return $session;
    }

    /**
     * storePayumToken.
     *
     * @param string $payumToken
     * @return static
     */
    protected function storePayumToken($payumToken)
    {
        $session = $this->getSessionFromRequest();
        $session->put($this->payumTokenId, $payumToken);
        $session->save();
    }

    /**
     * getPayumToken.
     *
     * @param string $payumToken
     * @return string
     */
    protected function getPayumToken($payumToken = null)
    {
        $session = $this->getSessionFromRequest();
        if (empty($payumToken) === true) {
            $payumToken = $session->get($this->payumTokenId);
        }
        $session->forget($this->payumTokenId);
        $session->save();

        return $payumToken;
    }

    /**
     * getStorage.
     *
     * @return \Payum\Core\Storage\StorageInterface
     */
    protected function getStorage()
    {
        $paymentModel = (in_array(EloquentPayment::class, array_keys($this->getPayum()->getStorages())) === true) ?
            EloquentPayment::class : PayumPayment::class;

        return $this->getPayum()->getStorage($paymentModel);
    }
}
