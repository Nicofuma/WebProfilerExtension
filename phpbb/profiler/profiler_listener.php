<?php
/**
*
* This file is part of the phpBB Forum Software package.
*
* @copyright (c) phpBB Limited <https://www.phpbb.com>
* @license GNU General Public License, version 2 (GPL-2.0)
*
* For full copyright and license information, please see
* the docs/CREDITS.txt file.
*
*/

namespace nicofuma\webprofiler\phpbb\profiler;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\Event\PostResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use \Symfony\Component\HttpKernel\KernelEvents;
use \Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Profiler\Profiler as symfony_profiler;

/**
* Extends the Symfony HttpKernel Profiler to use phpBB events with legacy front-end.
*/
class profiler_listener extends \Symfony\Component\HttpKernel\EventListener\ProfilerListener
{
	protected $symfony_request;
	protected $request_stack;
	protected $dispatcher;
	protected $http_kernel;
	protected $phpbb_root_path;
	protected $phpEx;
	protected $redirect_url = null;

	public function __construct(Request $symfony_request, RequestStack $request_stack, EventDispatcherInterface $dispatcher, HttpKernelInterface $http_kernel, $phpbb_root_path, $phpEx, symfony_profiler $profiler, RequestMatcherInterface $matcher = null, $onlyException = false, $onlyMasterRequests = false)
	{
		$this->symfony_request = $symfony_request;
		$this->request_stack = $request_stack;
		$this->dispatcher = $dispatcher;
		$this->http_kernel = $http_kernel;
		$this->phpbb_root_path = $phpbb_root_path;
		$this->phpEx = $phpEx;

		parent::__construct($profiler, $matcher, $onlyException, $onlyMasterRequests, $request_stack);
	}

	/**
	* Emulate the kernel request event
	*/
	public function on_common()
	{
		if (substr($GLOBALS['request']->server('SCRIPT_NAME'), -7) === 'app.php')
		{
			return;
		}

		try {
			$this->request_stack->push($this->symfony_request);

			$this->dispatcher->dispatch(KernelEvents::REQUEST,
				new GetResponseEvent(
					$this->http_kernel,
					$this->request_stack->getCurrentRequest(),
					HttpKernelInterface::MASTER_REQUEST
				));
		}
		catch (\Exception $e)
		{
		}
	}

	/**
	* Emulate the kernel response event
	*/
	public function on_garbage_collection()
	{
		if (substr($GLOBALS['request']->server('SCRIPT_NAME'), -7) === 'app.php')
		{
			return;
		}

		// An Exception is throw because the TraceableEventDispatcher
		// stop all the events when KernelEvents::RESPONSE is trigger.
		// But because we are emulating the event through the
		// garbage_collection event, we got an exception at the end
		// of the dispatching of the event.
		// (stop() is called on an already stopped() event)
		set_exception_handler(array($this, 'exception_handler'));

		try {
			$response = new Response('<html><body></body></html>');
			$this->dispatcher->dispatch(KernelEvents::RESPONSE,
				new FilterResponseEvent(
					$this->http_kernel,
					$this->request_stack->getCurrentRequest(),
					HttpKernelInterface::MASTER_REQUEST,
					$response
				));

			if ($this->redirect_url !== null)
			{
				$url = $this->redirect_url;

				// Redirect via an HTML form for PITA webservers
				if (@preg_match('#Microsoft|WebSTAR|Xitami#', getenv('SERVER_SOFTWARE')))
				{
					header('Refresh: 0; URL=' . $url);

					echo '<!DOCTYPE html>';
					echo '<html dir="Direction" lang="en">';
					echo '<head>';
					echo '<meta charset="utf-8">';
					echo '<meta http-equiv="refresh" content="0; url=' . str_replace('&', '&amp;', $url) . '" />';
					echo '<title>Redirect</title>';
					echo '</head>';
					echo '<body>';
					echo '<div style="text-align: center;"><a href="' . str_replace('&', '&amp;', $url) . '">Redirect</a></div>';
					echo '</body>';
					echo '</html>';

					$this->dispatcher->dispatch(KernelEvents::TERMINATE,
					new PostResponseEvent($this->http_kernel,
							$this->request_stack->getCurrentRequest(),
							$response
						)
					);
					exit;
				}

				// Behave as per HTTP/1.1 spec for others
				header('Location: ' . $url);
				$this->dispatcher->dispatch(KernelEvents::TERMINATE,
					new PostResponseEvent($this->http_kernel,
						$this->request_stack->getCurrentRequest(),
						$response
					)
				);
				exit;
			}
			else if ($response->getContent() !== '<html><body></body></html>')
			{
				if ('<html><body></body></html>' !== $response->getContent())
				{
					echo $response->getContent();
				}
			}

			$this->dispatcher->dispatch(KernelEvents::TERMINATE,
				new PostResponseEvent($this->http_kernel,
					$this->request_stack->getCurrentRequest(),
					$response
				)
			);
		}
		catch (\Exception $e)
		{dump($e);
		}
	}

	/**
	* Avoid the call to the controller resolver
	*
	* @param GetResponseEvent $event
	*/
	public function stop_propagation_request(GetResponseEvent $event)
	{
		if (substr($GLOBALS['request']->server('SCRIPT_NAME'), -7) !== 'app.php')
		{
			$event->stopPropagation();
		}
	}

	/**
	* Avoid the injection of the toolbar
	*
	* @param FilterResponseEvent $event
	*/
	public function onKernelResponse(FilterResponseEvent $event)
	{
		$result = parent::onKernelResponse($event);
		$content = ob_get_contents();

		if ($this->request_stack->getCurrentRequest()->getMethod() === 'POST' && empty($content))
		{
			$event->stopPropagation();
		}

		return $result;
	}

	public function on_redirect($url)
	{
		if (!$url['return'])
		{
			$this->redirect_url = $url['url'];
		}
	}

	public function exception_handler(\Exception $ex)
	{
	}

	public static function getSubscribedEvents()
	{
		return array_merge(parent::getSubscribedEvents(), array (
			KernelEvents::REQUEST		=> array('stop_propagation_request', 100),
			'core.common'				=> array('on_common', 1000),
			'core.garbage_collection'	=> array('on_garbage_collection', 1000),
			'core.functions.redirect'	=> array('on_redirect', 0),
		));
	}
}
