<?php
namespace Drupal\fastly\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\fastly\VclHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * UrlRewritesForm.
 *
 * @package Drupal\fastly\Form
 */
class UrlRewritesForm extends ConfigFormBase {

  /**
   * @var VclHandler
   */
  protected $vclHandler;

  /**
   * Constructs a UrlRewritesForm object.
   *
   * @param ConfigFactoryInterface $config_factory
   * @param \Drupal\fastly\VclHandler $vclHandler
   *   Vcl handler.
   */
  public function __construct(ConfigFactoryInterface $config_factory, VclHandler $vclHandler) {
    parent::__construct($config_factory);
    $this->vclHandler = $vclHandler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('config.factory'),
      $container->get('fastly.vclhandler'),
    );
  }

  /**
   * {@inheritDoc}
   */
  public function getFormId()
  {
    return 'url_rewrites';
  }

  /**
   * {@inheritDoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $config = $this->config('fastly.edge_modules.url_rewrites');
    $form['rules'] = [
      '#title' => t('Rules'),
      '#type' => 'fieldset',
      '#prefix' => '<div id="rules-wrapper">',
      '#suffix' => '</div>',
      '#tree' => TRUE,
    ];

    $rules = $config->get('rules') ?: [];
    if (!$form_state->get('rules')) {
      $form_state->set('rules', count($rules));
    }

    for ($i = 0; $i < $form_state->get('rules'); $i++) {

      $form['rules'][$i] = [
        '#type' => 'fieldset',
      ];
      $form['rules'][$i]['source'] = [
        '#title' => $this->t('Source path'),
        '#type' => 'textfield',
        '#default_value' => isset($rules[$i]['source']) ? $rules[$i]['source'] : '/sitemap.xml',
        '#description' => t('Incoming URL path only (no host) exact match e.g. /sitemap.xml'),
      ];
      $form['rules'][$i]['destination'] = [
        '#title' => $this->t('Destination path'),
        '#type' => 'textfield',
        '#default_value' => isset($rules[$i]['destination']) ? $rules[$i]['destination'] : '/media/sitemap.xml',
        '#description' => t('Destination path on origin (no host)'),
      ];
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['rules']['actions']['add'] = [
      '#type' => 'submit',
      '#value' => t('Add'),
      '#submit' => array('::addRule'),
      '#ajax' => [
        'callback' => '::returnRules',
        'wrapper' => 'rules-wrapper',
      ],
    ];
    if ($form_state->get('rules') > 1) {
      $form['rules']['actions']['remove'] = [
        '#type' => 'submit',
        '#value' => t('Remove'),
        '#submit' => array('::removeRule'),
        '#ajax' => [
          'callback' => '::returnRules',
          'wrapper' => 'rules-wrapper',
        ],
      ];
    }

    $form['disable'] = [
      '#type' => 'submit',
      '#value' => $this->t('Disable'),
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Upload'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $triggeringElement = $form_state->getTriggeringElement();
    if(str_contains($triggeringElement['#id'],'disable')){
      if($this->vclHandler->removeEdgeModule($this->getFormId())){
        $this->messenger()->addMessage(t('Edge module successfully disabled'));
      }else{
        $this->messenger()->addMessage(t('Error occurred while disabling module'));
      }
      $this->config('fastly.edge_modules.url_rewrites')
        ->set('rules', [])
        ->save();
    }else{
      $rules = $form_state->getValue('rules');
      unset($rules['actions']);
      $this->config('fastly.edge_modules.url_rewrites')
        ->set('rules', $rules)
        ->save();
      $response = $this->vclHandler->uploadEdgeModule($this->getFormId(),['rules' => $rules]);
      if($response) {
        $this->messenger()->addMessage(t('Edge module successfully enabled/updated'));
      } else{
        $this->messenger()->addMessage(t('There were errors while trying to enable/update Edge module'));
      }
    }
    $form_state->setRedirect('fastly.edge_modules');
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames()
  {
    return ['fastly.edge_modules.url_rewrites'];
  }

  /**
   * Callback to add rule.
   */
  public function addRule(array &$form, FormStateInterface $form_state) {
    $rules = $form_state->get('rules');
    unset($rules['actions']);
    $form_state->set('rules', ++$rules);
    $form_state->setRebuild();
  }

  /**
   * Callback for returning rules.
   */
  public function returnRules(array &$form, FormStateInterface $form_state) {
    return $form['rules'];
  }

  /**
   * Callback for remove rule.
   */
  public function removeRule(array &$form, FormStateInterface $form_state) {
    $rules = $form_state->get('rules');
    if ($rules > 1) {
      unset($rules['actions']);
      $form_state->set('rules', --$rules);
    }
    $form_state->setRebuild();
  }

}