<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Form\AlertRuleType;
use App\Alerting\RuleManager;
use App\Entity\AlertRule;
use App\Entity\Device;
use App\Enum\RuleSource;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AlertRuleController extends AbstractController
{
    public function __construct(private readonly RuleManager $rules)
    {
    }

    #[Route('/devices/{id}/rules/new', name: 'app_rule_new', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function new(Device $device, Request $request): Response
    {
        $rule = new AlertRule($device, RuleSource::Metric, $request->query->getString('target'));
        $form = $this->createForm(AlertRuleType::class, $rule);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->rules->create($rule);
            $this->addFlash('success', 'Dodano regułę.');

            return $this->redirectToRoute('app_device_show', ['id' => $device->getId(), '_fragment' => 'rules']);
        }

        return $this->render('admin/rule/form.html.twig', ['form' => $form, 'rule' => null, 'device' => $device]);
    }

    #[Route('/rules/{id}/edit', name: 'app_rule_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(AlertRule $rule, Request $request): Response
    {
        $before = ['source' => $rule->getSource()->value, 'target' => $rule->getTarget(), 'enabled' => $rule->isEnabled()];
        $form = $this->createForm(AlertRuleType::class, $rule);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->rules->update($rule, $before);
            $this->addFlash('success', 'Zapisano regułę. Nowe progi zostaną użyte przy kolejnym heartbeacie.');

            return $this->redirectToRoute('app_device_show', ['id' => $rule->getDevice()->getId(), '_fragment' => 'rules']);
        }

        return $this->render('admin/rule/form.html.twig', ['form' => $form, 'rule' => $rule, 'device' => $rule->getDevice()]);
    }

    #[Route('/rules/{id}/delete', name: 'app_rule_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(AlertRule $rule, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('rule-delete'.$rule->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Nieprawidłowy token CSRF.');
        }

        $deviceId = $rule->getDevice()->getId();
        $this->rules->delete($rule);
        $this->addFlash('success', 'Usunięto regułę.');

        return $this->redirectToRoute('app_device_show', ['id' => $deviceId, '_fragment' => 'rules']);
    }
}
