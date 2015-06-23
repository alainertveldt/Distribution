<?php

namespace Icap\PortfolioBundle\Controller\Internal;

use Claroline\CoreBundle\Entity\User;
use Icap\PortfolioBundle\Controller\Controller as BaseController;
use Icap\PortfolioBundle\Entity\Portfolio;
use Icap\PortfolioBundle\Entity\Widget;
use Symfony\Component\HttpFoundation\JsonResponse;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @Route("/internal/portfolio/{id}", requirements={"id" = "\d+"})
 */
class PortfolioWidgetController extends BaseController
{
    /**
     * @Route("/{type}", name="icap_portfolio_internal_portfolio_widget_get", options={"expose"=true})
     * @Method({"GET"})
     *
     * @ParamConverter("loggedUser", options={"authenticatedUser" = true})
     */
    public function getAction(Request $request, User $loggedUser, Portfolio $portfolio, $type)
    {
        $this->checkPortfolioToolAccess($loggedUser, $portfolio);

        $data = [];

        /** @var \Icap\PortfolioBundle\Entity\PortfolioWidget[] $portfolioWidgets */
        $portfolioWidgets = $this->getWidgetsManager()->getPortfolioWidgetsForWidgetPicker($loggedUser, $type);

        foreach ($portfolioWidgets as $widget) {
            $data[] = $this->getWidgetsManager()->getPortfolioWidgetData($widget);
        }

        $response = new JsonResponse();
        $response->setData($data);

        return $response;
    }

    /**
     * @Route("/{type}", name="icap_portfolio_internal_portfolio_widget_post")
     * @Method({"POST"})
     *
     * @ParamConverter("loggedUser", options={"authenticatedUser" = true})
     */
    public function postAction(Request $request, User $loggedUser, Portfolio $portfolio, $type)
    {
        $this->checkPortfolioToolAccess($loggedUser, $portfolio);

        $response      = new JsonResponse();
        $widgetManager = $this->getWidgetsManager();
        $widgetsConfig = $widgetManager->getWidgetsConfig();
        $data          = array();
        $statusCode    = Response::HTTP_BAD_REQUEST;

        if (isset($widgetsConfig[$type])) {
            if (!$widgetsConfig[$type]['isUnique'] || (
                    $widgetsConfig[$type]['isUnique']
                    && null === $this->getDoctrine()->getRepository('IcapPortfolioBundle:Widget\AbstractWidget')->findOneByTypeAndPortfolio($type, $portfolio)
                )) {
                $newWidget  = $widgetManager->getNewWidget($portfolio, $type);
                $data       = $widgetManager->handle($newWidget, $type, $request->request->all(), $this->get('kernel')->getEnvironment());
                $statusCode = Response::HTTP_CREATED;
            }
        }

        $response
            ->setData($data)
            ->setStatusCode($statusCode);

        return $response;
    }

    /**
     * @Route("/{type}/{widgetId}", name="icap_portfolio_internal_portfolio_widget_put", requirements={"widgetId" = "\d+"})
     * @Method({"PUT"})
     *
     * @ParamConverter("loggedUser", options={"authenticatedUser" = true})
     */
    public function putAction(Request $request, User $loggedUser, Portfolio $portfolio, $type, $widgetId)
    {
        $this->checkPortfolioToolAccess($loggedUser, $portfolio);

        /** @var \Icap\PortfolioBundle\Repository\Widget\AbstractWidgetRepository $abstractWidgetRepository */
        $abstractWidgetRepository = $this->getDoctrine()->getRepository('IcapPortfolioBundle:Widget\AbstractWidget');

        if (null === $widgetId) {
            $widget = $abstractWidgetRepository->findOneByTypeAndPortfolio($type, $portfolio);
        }
        else {
            $widget = $abstractWidgetRepository->find($widgetId);
        }

        $data = $this->getWidgetsManager()->handle($widget, $type, $request->request->all(), $this->get('kernel')->getEnvironment());

        $response = new JsonResponse();
        $response->setData($data);

        return $response;
    }

    /**
     * @Route("/{type}/{widgetId}", name="icap_portfolio_internal_portfolio_widget_delete", requirements={"widgetId" = "\d+"})
     * @Method({"DELETE"})
     *
     * @ParamConverter("loggedUser", options={"authenticatedUser" = true})
     */
    public function deleteAction(Request $request, User $loggedUser, Portfolio $portfolio, $type, $widgetId)
    {
        $this->checkPortfolioToolAccess($loggedUser, $portfolio);

        /** @var \Icap\PortfolioBundle\Repository\Widget\AbstractWidgetRepository $abstractWidgetRepository */
        $abstractWidgetRepository = $this->getDoctrine()->getRepository('IcapPortfolioBundle:Widget\AbstractWidget');

        if (null === $widgetId) {
            $widget = $abstractWidgetRepository->findOneByTypeAndPortfolio($type, $portfolio);
        }
        else {
            $widget = $abstractWidgetRepository->find($widgetId);
        }

        $response = new JsonResponse();

        try {
            $this->getWidgetsManager()->deleteWidget($widget);

        } catch(\Exception $exception){
            $response->setStatusCode(Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $response;
    }
}
 