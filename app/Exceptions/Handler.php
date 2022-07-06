<?php

namespace App\Exceptions;

use App\Dorcas\Enum\ResponseStatus;
use Exception;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Lumen\Exceptions\Handler as ExceptionHandler;
use League\OAuth2\Server\Exception\OAuthServerException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that should not be reported.
     *
     * @var array
     */
    protected $dontReport = [
        AuthorizationException::class,
    ];

    /**
     * A list of exceptions that denote something was not found.
     *
     * @var array
     */
    protected $notFoundExceptions = [
        ModelNotFoundException::class,
        NotFoundHttpException::class,
        RecordNotFoundException::class
    ];

    protected $clientInputExceptions = [
        \UnexpectedValueException::class,
        AuthorizationException::class,
        OAuthServerException::class,
        ApplicationAccessDeniedException::class
    ];
    
    /**
     * Report or log an exception.
     *
     * This is a great spot to send exceptions to Sentry, Bugsnag, etc.
     *
     * @param  \Exception $e
     *
     * @return void
     * @throws Exception
     */
    public function report(Throwable $e)
    {
        parent::report($e);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Exception  $e
     * @return \Illuminate\Http\Response
     */
    public function render($request, Throwable $e)
    {
        $status = 500;
        # the status code
        $exceptionClass = get_class($e);
        # we get the class name for the exception
        if (in_array($exceptionClass, $this->notFoundExceptions)) {
            $status = 404;
            $message = $exceptionClass === RecordNotFoundException::class ?
                $e->getMessage() : 'Could not find what you were looking for.';
            # our response message
            $response = [
                'status' => $status,
                'code' => ResponseStatus::NOT_FOUND,
                'title' => $message,
                'source' => array_merge($request->all(), ['path' => $request->getPathInfo()])
            ];

        } elseif ($e instanceof MethodNotAllowedHttpException) {
            $status = 405;
            $response = [
                'status' => $status,
                'code' => ResponseStatus::HTTP_ERROR,
                'title' => 'This method is not allowed for this endpoint.',
                'source' => array_merge($request->all(),
                    ['path' => $request->getPathInfo(), 'method' => $request->getMethod()])
            ];

        } elseif ($e instanceof ValidationException) {
            $status = 400;
            $response = [
                'status' => $status,
                'code' => ResponseStatus::VALIDATION_FAILED,
                'title' => 'Some validation errors were encountered while processing your request',
                'source' => validation_errors_to_messages($e)
            ];
    
        } elseif ($e instanceof ApplicationAccessDeniedException) {
            $status = 403;
            $response = [
                'status' => $status,
                'code' => ResponseStatus::HTTP_ERROR,
                'title' => $e->getMessage(),
                'source' => array_merge($request->all(),
                    ['path' => $request->getPathInfo(), 'method' => $request->getMethod()])
            ];

        } elseif (in_array($exceptionClass, $this->clientInputExceptions)) {
            $status = 400;
            $response = [
                'status' => $status,
                'code' => ResponseStatus::INPUT_ERROR,
                'title' => $e->getMessage(),
                'source' => array_merge($request->all(),
                    ['path' => $request->getPathInfo(), 'method' => $request->getMethod()])
            ];

        } elseif ($e instanceof DeletingFailedException) {
            $response = [
                'status' => $status,
                'code' => ResponseStatus::EXCEPTION,
                'title' => $e->getMessage(),
                'source' => array_merge($request->all(),
                    ['path' => $request->getPathInfo(), 'method' => $request->getMethod()])
            ];

        } elseif ($e instanceof AuthenticationException) {
            $status = 401;
            $response = [
                'status' => $status,
                'code' => ResponseStatus::HTTP_ERROR,
                'title' => $e->getMessage(),
                'source' => array_merge($request->all(),
                    ['path' => $request->getPathInfo(), 'method' => $request->getMethod()])
            ];
        } else {
            $response = [
                'status' => $status,
                'code' => ResponseStatus::EXCEPTION,
                'title' => $e->getMessage(),
            ];
        }
        return response()->json(['errors' => [$response]], $status);
    }
}
