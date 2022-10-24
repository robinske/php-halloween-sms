<?php

use DI\Container;
use HappyHalloween\Filter\SpaceFilenamesFilter;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Filter\StringTrim;
use Laminas\Filter\StripTags;
use Laminas\InputFilter\Input;
use Laminas\InputFilter\InputFilter;
use Laminas\Validator\NotEmpty;
use Laminas\Validator\Regex;
use Laminas\Validator\StringLength;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use SpacesAPI\Spaces;
use Twilio\Rest\Client;



require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();


$container = new Container;
$container->set(InputFilter::class, function(): InputFilter {
    $image = new Input('image');
    $image->getValidatorChain()
        ->attach(new NotEmpty());
    $image->getFilterChain()
        ->attach(new StringTrim())
        ->attach(new StripTags());

    $message = new Input('message');
    $message->getValidatorChain()
        ->attach(new NotEmpty())
        ->attach(new StringLength(['max' => 320]));
    $message->getFilterChain()
        ->attach(new StringTrim())
        ->attach(new StripTags());

    $phoneNumber = new Input('phone_number');
    $phoneNumber->getValidatorChain()
        ->attach(new Regex(['pattern' => '/^\+[1-9]\d{1,14}$/']));
    $phoneNumber->getFilterChain()
        ->attach(new StringTrim())
        ->attach(new StripTags());

    $inputFilter = new InputFilter();
    $inputFilter->add($image);
    $inputFilter->add($message);
    $inputFilter->add($phoneNumber);

    return $inputFilter;
});

$container->set('images', function (): array {
    $space = (
        new Spaces(
            $_SERVER['SPACES_KEY'],
            $_SERVER['SPACES_SECRET'],
            $_SERVER['SPACES_REGION'],
        )
    )->space($_SERVER['SPACE_NAME']);

    return (
        new SpaceFilenamesFilter(
            sprintf(
                'https://%s.%s.digitaloceanspaces.com',
                $_SERVER['SPACE_NAME'],
                $_SERVER['SPACES_REGION'],
            )
        )
    )->filterFilenames($space->listFiles());
});

$container->set(Client::class, function (): Client {
    return new Client(
        $_SERVER["TWILIO_ACCOUNT_SID"],
        $_SERVER["TWILIO_AUTH_TOKEN"]
    );
});

AppFactory::setContainer($container);
$app = AppFactory::create();
$app->add(TwigMiddleware::create(
    $app,
    Twig::create(
        __DIR__ . '/../templates/',
        ['cache' => false]
    )
));


$app->map(['GET','POST'], '/',
    function (Request $request, Response $response, array $args)
    {
        $data = [];

        if ($request->getMethod() === 'POST') {
            $inputFilter = $this->get(InputFilter::class);
            $inputFilter->setData((array)$request->getParsedBody());
            if (! $inputFilter->isValid()) {
                $data['errors'] = $inputFilter->getMessages();
                $data['values'] = $inputFilter->getValues();
            } else {
                $twilio = $this->get(Client::class);
                $twilio->messages
                    ->create(
                        $inputFilter->getValue('phone_number'),
                        [
                            "body" => $inputFilter->getValue('message'),
                            "from" => $_SERVER['TWILIO_PHONE_NUMBER'],
                            "mediaUrl" => [
                                sprintf(
                                '%s/%s.png',
                                $_SERVER["IMAGE_URL_BASE"],
                                $inputFilter->getValue('image')
                            )
                        ]
                    ]
                );

                return $response
                    ->withHeader('Location', '/thank-you')
                    ->withStatus(302);
            }
        }

        $data['images'] = $this->get('images');
        $view = Twig::fromRequest($request);

        return $view->render($response, 'default.html.twig', $data);
    }
);

$app->get('/thank-you',
    function (Request $request, Response $response, array $args)
    {
        return Twig::fromRequest($request)
            ->render($response, 'thank-you.html.twig', []);
    }
);



$app->run();
