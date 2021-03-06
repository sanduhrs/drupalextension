<?php

namespace Drupal\DrupalExtension\Context;

use Behat\MinkExtension\Context\MinkContext;
use Behat\Behat\Exception\PendingException;
use Behat\Mink\Exception\UnsupportedDriverActionException;

use Drupal\Drupal;
use Drupal\DrupalExtension\Context\DrupalSubContextInterface;

use Symfony\Component\Process\Process;

use Behat\Behat\Context\Step\Given;
use Behat\Behat\Context\Step\When;
use Behat\Behat\Context\Step\Then;

use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;

use Behat\Mink\Driver\Selenium2Driver as Selenium2Driver;

/**
 * Features context.
 */
class DrupalContext extends MinkContext implements DrupalAwareInterface {

  private $drupal, $drupalParameters;

  /**
   * Basic auth user and password.
   */
  public $basic_auth;

  /**
   * Current authenticated user.
   *
   * A value of FALSE denotes an anonymous user.
   */
  public $user = FALSE;

  /**
   * Keep track of all users that are created so they can easily be removed.
   */
  private $users = array();

  /**
   * Initialize subcontexts.
   *
   * @param array $subcontexts
   *   Array of sub-context class names to initiate, keyed by sub-context alias.
   */
  public function initializeSubContexts(array $subcontexts) {
    foreach ($subcontexts as $path => $subcontext) {
      if (!file_exists($path)) {
        throw new \RuntimeException(sprintf('Subcontext path %s path does not exist.', $path));
      }

      // Load file.
      require_once $path;
    }

    // @todo this seems overkill.
    $classes = get_declared_classes();
    $subcontext_classes = array();
    foreach ($classes as $class) {
      $reflect = new \ReflectionClass($class);
      if ($reflect->implementsInterface('Drupal\DrupalExtension\Context\DrupalSubContextInterface')) {
        $alias = $class::getAlias();
        $this->useContext($alias, new $class);
      }
    }
  }

  /**
   * Set Drupal instance.
   */
  public function setDrupal(Drupal $drupal) {
    $this->drupal = $drupal;
  }

  /**
   * Get Drupal instance.
   */
  public function getDrupal() {
    return $this->drupal;
  }

  /**
   * Set parameters provided for Drupal.
   */
  public function setDrupalParameters(array $parameters) {
    $this->drupalParameters = $parameters;
  }

  /**
   * Returns a specific Drupal parameter.
   *
   * @param string $name
   *   Parameter name.
   *
   * @return mixed
   */
  public function getDrupalParameter($name) {
    return isset($this->drupalParameters[$name]) ? $this->drupalParameters[$name] : NULL;
  }

  /**
   * Returns a specific Drupal text value.
   *
   * @param string $name
   *   Text value name, such as 'log_out', which corresponds to the default 'Log
   *   out' link text.
   */
  public function getDrupalText($name) {
    $text = $this->getDrupalParameter('text');
    if (!isset($text[$name])) {
      throw new \Exception(sprintf('No such Drupal string: %s', $name));
    }
    return $text[$name];
  }

  /**
   * Get active Drupal Driver.
   */
  public function getDriver($name = NULL) {
    return $this->getDrupal()->getDriver($name);
  }

  /**
   * Run before every scenario.
   *
   * @BeforeScenario
   */
  public function beforeScenario($event) {
    if (isset($this->basic_auth)) {
      $driver = $this->getSession()->getDriver();
      if ($driver instanceof Selenium2Driver) {
        // Continue if this is a Selenium driver, since this is handled in
        // locatePath().
      }
      else {
        // Setup basic auth.
        $this->getSession()->setBasicAuth($this->basic_auth['username'], $this->basic_auth['password']);
      }
    }
  }

  /**
   * Check for shell access (via drush).
   *
   * @BeforeScenario @shellAccess
   */
  public function checkShellAccess() {
    // @todo fix/move
    return;
    // @todo check that this is a functioning alias.
    // See http://drupal.org/node/1615450
    if (!$this->drushAlias) {
      throw new pendingException('This scenario requires shell access.');
    }
  }

  /**
   * Run after every scenario.
   *
   * @AfterScenario
   */
  public function afterScenario($event) {
    // Remove any users that were created.
    if (!empty($this->users)) {
      foreach ($this->users as $user) {
        $this->getDriver()->userDelete($user);
      }
    }
  }

  /**
   * Override MinkContext::locatePath() to work around Selenium not supporting
   * basic auth.
   */
  public function locatePath($path) {
    $driver = $this->getSession()->getDriver();
    if ($driver instanceof Selenium2Driver && isset($this->basic_auth)) {
      // Add the basic auth parameters to the base url. This only works for
      // Firefox.
      $startUrl = rtrim($this->getMinkParameter('base_url'), '/') . '/';
      $startUrl = str_replace('://', '://' . $this->basic_auth['username'] . ':' . $this->basic_auth['password'] . '@', $startUrl);
      return 0 !== strpos($path, 'http') ? $startUrl . ltrim($path, '/') : $path;
    }
    else {
      return parent::locatePath($path);
    }
  }

  /**
   * @defgroup helper functions
   * @{
   */

  /**
   * Helper function to generate a random string of arbitrary length.
   *
   * Copied from drush_generate_password().
   *
   * @param int $length
   *   Number of characters the generated string should contain.
   *
   * @return string
   *   The generated string.
   */
  public function randomString($length = 10) {
    // This variable contains the list of allowable characters for the
    // password. Note that the number 0 and the letter 'O' have been
    // removed to avoid confusion between the two. The same is true
    // of 'I', 1, and 'l'.
    $allowable_characters = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    // Zero-based count of characters in the allowable list:
    $len = strlen($allowable_characters) - 1;

    // Declare the password as a blank string.
    $pass = '';

    // Loop the number of times specified by $length.
    for ($i = 0; $i < $length; $i++) {

      // Each iteration, pick a random character from the
      // allowable string and append it to the password:
      $pass .= $allowable_characters[mt_rand(0, $len)];
    }

    return $pass;
  }

  /**
   * Helper function to login the current user.
   */
  public function login() {
    // Check if logged in.
    if ($this->loggedIn()) {
      $this->logout();
    }

    if (!$this->user) {
      throw new \Exception('Tried to login without a user.');
    }

    $this->getSession()->visit($this->locatePath('/user'));
    $element = $this->getSession()->getPage();
    $element->fillField($this->getDrupalText('username_field'), $this->user->name);
    $element->fillField($this->getDrupalText('password_field'), $this->user->pass);
    $submit = $element->findButton($this->getDrupalText('log_in'));
    if (empty($submit)) {
      throw new \Exception(sprintf("No submit button at %s", $this->getSession()->getCurrentUrl()));
    }

    // Log in.
    $submit->click();

    if (!$this->loggedIn()) {
      throw new \Exception(sprintf("Failed to log in as user '%s' with role '%s'", $this->user->name, $this->user->role));
    }
  }

  /**
   * Helper function to logout.
   */
  public function logout() {
    $this->getSession()->visit($this->locatePath('/user/logout'));
  }

  /**
   * Determine if the a user is already logged in.
   */
  public function loggedIn() {
    $session = $this->getSession();
    $session->visit($this->locatePath('/'));

    // If a logout link is found, we are logged in. While not perfect, this is
    // how Drupal SimpleTests currently work as well.
    $element = $session->getPage();
    return $element->findLink($this->getDrupalText('log_out'));
  }

  /**
   * @} End of defgroup "helper functions".
   */

  /**
   * @defgroup mink extensions
   * @{
   * Wrapper step definitions to the Mink extensions in order to implement
   * alternate wording for tests.
   */

  /**
   * Visit a given path, and additionally check for HTTP response code 200.
   *
   * @Given /^(?:that I|I) am at "(?P<path>[^"]*)"$/
   *
   * @throws UnsupportedDriverActionException
   */
  public function iAmAt($path) {
    $this->getSession()->visit($this->locatePath($path));

    // If available, add extra validation that this is a 200 response.
    try {
      $this->getSession()->getStatusCode();
      return new Given('I should get a "200" HTTP response');
    }
    catch (UnsupportedDriverActionException $e) {
      // Simply continue on, as this driver doesn't support HTTP response codes.
    }
  }

  /**
   * @When /^I visit "(?P<path>[^"]*)"$/
   */
  public function iVisit($path) {
    // Use Drupal Context 'I am at'.
    return new Given("I am at \"$path\"");
  }

  /**
   * @When /^I click "(?P<link>[^"]*)"$/
   */
  public function iClick($link) {
    // Use the Mink Extenstion step definition.
    return new Given("I follow \"$link\"");
  }

  /**
   * @Given /^for "(?P<field>[^"]*)" I enter "(?P<value>[^"]*)"$/
   * @Given /^I enter "(?P<value>[^"]*)" for "(?P<field>[^"]*)"$/
   */
  public function forIenter($field, $value) {
    // Use the Mink Extenstion step definition.
    return new Given("I fill in \"$field\" with \"$value\"");
  }

  /**
   * @When /^I press the "(?P<button>[^"]*)" button$/
   */
  public function iPressTheButton($button) {
    // Use the Mink Extenstion step definition.
    return new Given("I press \"$button\"");
  }

  /**
   * @Then /^I should see the link "(?P<link>[^"]*)"$/
   */
  public function iShouldSeeTheLink($link) {
    $element = $this->getSession()->getPage();
    $result = $element->findLink($link);
    if (empty($result)) {
      throw new \Exception(sprintf("No link to '%s' on the page %s", $link, $this->getSession()->getCurrentUrl()));
    }
  }

  /**
   * @Then /^I should not see the link "(?P<link>[^"]*)"$/
   */
  public function iShouldNotSeeTheLink($link) {
    $element = $this->getSession()->getPage();
    $result = $element->findLink($link);
    if ($result) {
      throw new \Exception(sprintf("The link '%s' was present on the page %s and was not supposed to be", $link, $this->getSession()->getCurrentUrl()));
    }
  }

  /**
   * @Then /^I (?:|should )see the heading "(?P<heading>[^"]*)"$/
   */
  public function iShouldSeeTheHeading($heading) {
    $element = $this->getSession()->getPage();
    foreach (array('h1', 'h2', 'h3', 'h4', 'h5', 'h6') as $tag) {
      $results = $element->findAll('css', $tag);
      foreach ($results as $result) {
        if ($result->getText() == $heading) {
          return;
        }
      }
    }
    throw new \Exception(sprintf("The text '%s' was not found in any heading on the page %s", $heading, $this->getSession()->getCurrentUrl()));
  }

  /**
   * Find a heading in a specific region.
   *
   * @Then /^I should see the heading "(?P<heading>[^"]*)" in the "(?P<region>[^"]*)"(?:| region)$/
   * @Then /^I should see the "(?P<heading>[^"]*)" heading in the "(?P<region>[^"]*)"(?:| region)$/
   */
  public function assertRegionHeading($heading, $region) {
    $page = $this->getSession()->getPage();
    $regionObj = $page->find('region', $region);
    if (!$regionObj) {
      throw new \Exception(sprintf('No region "%s" found on the page %s.', $region, $this->getSession()->getCurrentUrl()));
    }


    foreach (array('h1', 'h2', 'h3', 'h4', 'h5', 'h6') as $tag) {
      $elements = $regionObj->findAll('css', $tag);
      if (!empty($elements)) {
        foreach ($elements as $element) {
          if (trim($element->getText()) === $heading) {
            return;
          }
        }
      }
    }

    throw new \Exception(sprintf('The heading "%s" was not found in the "%s" region on the page %s', $heading, $region, $this->getSession()->getCurrentUrl()));
  }

  /**
   * @When /^I (?:follow|click) "(?P<link>[^"]*)" in the "(?P<region>[^"]*)"(?:| region)$/
   */
  public function assertRegionLinkFollow($link, $region) {
    // Find the region requested
    $regionObj = $this->getSession()->getPage()->find('region', $region);
    if (empty($regionObj)) {
      throw new \Exception(sprintf('No region "%s" found on the page %s.', $region, $this->getSession()->getCurrentUrl()));
    }
    // Find the link within the region
    $linkObj = $regionObj->findLink($link);
    if (empty($linkObj)) {
      throw new \Exception(sprintf('The link "%s" was not found in the region "%s" on the page %s', $link, $region, $this->getSession()->getCurrentUrl()));
    }
    $linkObj->click();
  }

  /**
   * @Then /^I should see the link "(?P<link>[^"]*)" in the "(?P<region>[^"]*)"(?:| region)$/
   */
  public function assertLinkRegion($link, $region) {
    $element = $this->getSession()->getPage()->find('region', $region);
    if (!$element) {
      throw new \Exception(sprintf('No region "%s" found on the page %s.', $region, $this->getSession()->getCurrentUrl()));
    }
    $result = $element->findLink($link);
    if (empty($result)) {
      throw new \Exception(sprintf('No link to "%s" in the "%s" region on the page %s', $link, $region, $this->getSession()->getCurrentUrl()));
    }
  }

  /**
   * @Then /^I should see (?:the text |)"(?P<text>[^"]*)" in the "(?P<region>[^"]*)"(?:| region)$/
   */
  public function assertRegionText($text, $region) {
    // Find the region requested
    $regionObj = $this->getSession()->getPage()->find('region', $region);
    if (empty($regionObj)) {
      throw new \Exception(sprintf('No region "%s" found on the page %s.', $region, $this->getSession()->getCurrentUrl()));
    }
    // Find the text within the region
    $regionText = $regionObj->getText();
    if (strpos($regionText, $text) === false) {
      throw new \Exception(sprintf("The text '%s' was not found in the region '%s' on the page %s", $text, $region, $this->getSession()->getCurrentUrl()));
    }
  }

  /**
   * @Then /^I should not see (?:the text |)"(?P<text>[^"]*)" in the "(?P<region>[^"]*)"(?:| region)$/
   */
  public function assertNotRegionText($text, $region) {
    // Find the region requested
    $regionObj = $this->getSession()->getPage()->find('region', $region);
    if (empty($regionObj)) {
      throw new \Exception(sprintf('No region "%s" found on the page %s.', $region, $this->getSession()->getCurrentUrl()));
    }
    // Find the text within the region
    $regionText = $regionObj->getText();
    if (strpos($regionText, $text) !== FALSE) {
      throw new \Exception(sprintf('The text "%s" was found in the region "%s" on the page %s', $text, $region, $this->getSession()->getCurrentUrl()));
    }
  }

  /**
   * Checks, if a button with id|name|title|alt|value exists or not and pressess the same
   *
   * @param $button
   *   string The id|name|title|alt|value of the button to be pressed
   * @param $region
   *   string The region in which the button should be pressed
   *
   * @Given /^I press "(?P<button>[^"]*)" in the "(?P<region>[^"]*)"(?:| region)$/
   */
  public function assertRegionPressButton($button, $region) {
    $regionObj = $this->getSession()->getPage()->find('region', $region);
    $buttonObj = $regionObj->findButton($button);
    if (empty($buttonObj)) {
      throw new \Exception(sprintf("The button '%s' was not found in the region '%s' on the page %s", $button, $region, $this->getSession()->getCurrentUrl()));
    }
    $regionObj->pressButton($button);
  }

  /**
   * Fills in a form field with id|name|title|alt|value in the specified region.
   *
   * @Given /^(?:|I )fill in "(?P<value>(?:[^"]|\\")*)" for "(?P<field>(?:[^"]|\\")*)" in the "(?P<region>[^"]*)"(?:| region)$/
   * @Given /^(?:|I )fill in "(?P<field>(?:[^"]|\\")*)" with "(?P<value>(?:[^"]|\\")*)" in the "(?P<region>[^"]*)"(?:| region)$/
   */
  public function regionFillField($field, $value, $region) {
    $field = $this->fixStepArgument($field);
    $value = $this->fixStepArgument($value);
    $regionObj = $this->getSession()->getPage()->find('region', $region);
    $regionObj->fillField($field, $value);
  }

  /**
   * @Then /^(?:I|I should) see the text "(?P<text>[^"]*)"$/
   */
  public function iShouldSeeTheText($text) {
    // Use the Mink Extension step definition.
    return new Given("I should see text matching \"$text\"");
  }

  /**
   * @Then /^I should not see the text "(?P<text>[^"]*)"$/
   */
  public function iShouldNotSeeTheText($text) {
    // Use the Mink Extension step definition.
    return new Given("I should not see text matching \"$text\"");
  }

  /**
   * @Then /^I should get a "(?P<code>[^"]*)" HTTP response$/
   */
  public function iShouldGetAHttpResponse($code) {
    // Use the Mink Extension step definition.
    return new Given("the response status code should be $code");
  }

  /**
   * @Then /^I should not get a "(?P<code>[^"]*)" HTTP response$/
   */
  public function iShouldNotGetAHttpResponse($code) {
    // Use the Mink Extension step definition.
    return new Given("the response status code should not be $code");
  }

  /**
   * @Given /^I check the box "(?P<checkbox>[^"]*)"$/
   */
  public function iCheckTheBox($checkbox) {
    // Use the Mink Extension step definition.
    return new Given("I check \"$checkbox\"");
  }

  /**
   * @Given /^I uncheck the box "(?P<checkbox>[^"]*)"$/
   */
  public function iUncheckTheBox($checkbox) {
    // Use the Mink Extension step definition.
    return new Given("I uncheck \"$checkbox\"");
  }

  /**
   * @When /^I select the radio button "(?P<label>[^"]*)" with the id "(?P<id>[^"]*)"$/
   * @When /^I select the radio button "(?P<label>[^"]*)"$/
   * @TODO convert to mink extension.
   */
  public function iSelectTheRadioButtonWithTheId($label, $id = FALSE) {
    $element = $this->getSession()->getPage();
    $radiobutton = $id ? $element->findById($id) : $element->find('named', array('radio', $this->getSession()->getSelectorsHandler()->xpathLiteral($label)));
    if ($radiobutton === NULL) {
      throw new \Exception(sprintf('The radio button with "%s" was not found on the page %s', $id ? $id : $label, $this->getSession()->getCurrentUrl()));
    }
    $value = $radiobutton->getAttribute('value');
    $labelonpage = $radiobutton->getParent()->getText();
    if ($label != $labelonpage) {
      throw new \Exception(sprintf("Button with id '%s' has label '%s' instead of '%s' on the page %s", $id, $labelonpage, $label, $this->getSession()->getCurrentUrl()));
    }
    $radiobutton->selectOption($value, FALSE);
  }

  /**
   * @} End of defgroup "mink extensions"
   */

  /**
   * @defgroup drupal extensions
   * @{
   * Drupal-specific step definitions.
   */

  /**
   * @Given /^I am an anonymous user$/
   * @Given /^I am not logged in$/
   */
  public function iAmAnAnonymousUser() {
    // Verify the user is logged out.
    if ($this->loggedIn()) {
      $this->logout();
    }
  }

  /**
   * Creates and authenticates a user with the given role via Drush.
   *
   * @Given /^I am logged in as a user with the "(?P<role>[^"]*)" role$/
   */
  public function iAmLoggedInWithRole($role) {
    // Check if a user with this role is already logged in.
    if ($this->loggedIn() && $this->user && isset($this->user->role) && $this->user->role == $role) {
      return TRUE;
    }

    // Create user (and project)
    $user = (object) array(
      'name' => $this->randomString(8),
      'pass' => $this->randomString(16),
      'role' => $role,
    );
    $user->mail = "{$user->name}@example.com";


    // Create a new user.
    $this->getDriver()->userCreate($user);

    $this->users[] = $this->user = $user;

    if ($role == 'authenticated user') {
      // Nothing to do.
    }
    else {
      $this->getDriver()->userAddRole($user, $role);
    }

    // Login.
    $this->login();

    return TRUE;
  }

  /**
   * Attempts to find a link in a table row containing giving text. This is for
   * administrative pages such as the administer content types screen found at
   * `admin/structure/types`.
   *
   * @Given /^I click "(?P<link>[^"]*)" in the "(?P<row_text>[^"]*)" row$/
   */
  public function iClickInTheRow($link, $row_text) {
    $page = $this->getSession()->getPage();
    $rows = $page->findAll('css', 'tr');
    if (!$rows) {
      throw new \Exception(sprintf('No rows found on the page %s', $this->getSession()->getCurrentUrl()));
    }
    $row_found = FALSE;
    foreach ($rows as $row) {
      if (strpos($row->getText(), $row_text) !== FALSE) {
        $row_found = TRUE;
        // Found text in this row, now find link in a cell.
        $cells = $row->findAll('css', 'td');
        if (!$cells) {
          throw new \Exception(sprintf('No cells found in table row on the page %s', $this->getSession()->getCurrentUrl()));
        }
        foreach ($cells as $cell) {
          if ($element = $cell->findLink($link)) {
            $element->click();
            return;
          }
        }
      }
    }
    if ($row_found) {
      throw new \Exception(sprintf('Found a row containing "%s", but no "%s" link on the page %s', $row_text, $link, $this->getSession()->getCurrentUrl()));
    }
    else {
      throw new \Exception(sprintf('Failed to find a row containing "%s" on the page %s', $row_text, $this->getSession()->getCurrentUrl()));
    }
  }

  /**
   * @Given /^the cache has been cleared$/
   */
  public function theCacheHasBeenCleared() {
    $this->getDriver()->clearCache();
  }

  /**
   * @Given /^I run cron$/
   */
  public function iRunCron() {
    $this->getDriver()->runCron();
  }

  /**
   * @Given /^I am viewing (?:a|an) "(?P<type>[^"]*)" node with the title "(?P<title>[^"]*)"$/
   * @Given /^(?:a|an) "(?P<type>[^"]*)" node with the title "(?P<title>[^"]*)"$/
   */
  public function createNode($type, $title) {
    // @todo make this easily extensible.
    $node = (object) array(
      'title' => $title,
      'type' => $type,
      'body' => $this->randomString(255),
    );
    $saved = $this->getDriver()->createNode($node);

    // Set internal page on the new node.
    $this->getSession()->visit($this->locatePath('/node/' . $saved->nid));
  }

  /**
   * @Given /^I am viewing my "(?P<type>[^"]*)" node with the title "(?P<title>[^"]*)"$/
   */
  public function createMyNode($type, $title) {
    if (!$this->user->uid) {
      throw new \Exception(sprintf('There is no current logged in user to create a node for.'));
    }
    $node = (object) array(
      'title' => $title,
      'type' => $type,
      'body' => $this->randomString(255),
      'uid' => $this->user->uid,
    );
    $saved = $this->getDriver()->createNode($node);

    // Set internal page on the new node.
    $this->getSession()->visit($this->locatePath('/node/' . $saved->nid));
  }

  /**
   * @Given /^"(?P<type>[^"]*)" nodes:$/
   */
  public function createNodes($type, TableNode $nodesTable) {
    foreach ($nodesTable->getHash() as $nodeHash) {
      $node = (object) $nodeHash;
      $node->type = $type;
      $this->getDriver()->createNode($node);
    }
  }

  /**
   * @Given /^I am viewing (?:a|an) "(?P<vocabulary>[^"]*)" term with the name "(?P<name>[^"]*)"$/
   * @Given /^(?:a|an) "(?P<vocabulary>[^"]*)" term with the name "(?P<name>[^"]*)"$/
   */
  public function createTerm($vocabulary, $name) {
    // @todo make this easily extensible.
    $term = (object) array(
      'name' => $name,
      'vocabulary_machine_name' => $vocabulary,
      'description' => $this->randomString(255),
    );
    $saved = $this->getDriver()->createTerm($term);

    // Set internal page on the new node.
    $this->getSession()->visit($this->locatePath('/taxonomy/term/' . $saved->tid));
  }

  /**
   * @Given /^"(?P<vocabulary>[^"]*)" terms:$/
   */
  public function createTerms($vocabulary, TableNode $termsTable) {
    foreach ($termsTable->getHash() as $termsHash) {
      $term = (object) $termsHash;
      $term->vocabulary_machine_name = $vocabulary;
      $this->getDriver()->createTerm($term);
    }
  }

  /**
   * Checks if the current page contains the given error message
   *
   * @param $message
   *   string The text to be checked
   *
   * @Then /^I should see the error message(?:| containing) "([^"]*)"$/
   */
  public function iShouldSeeTheErrorMessage($message) {
    $errorSelector = $this->getDrupalSelector('error_message_selector');
    $errorSelectorObj = $this->getSession()->getPage()->find("css", $errorSelector);
    if(empty($errorSelectorObj)) {
      throw new \Exception(sprintf("The page '%s' does not contain any error messages", $this->getSession()->getCurrentUrl()));
    }
    if (strpos(trim($errorSelectorObj->getText()), $message) === FALSE) {
      throw new \Exception(sprintf("The page '%s' does not contain the error message '%s'", $this->getSession()->getCurrentUrl(), $message));
    }
  }

  /**
   * Checks if the current page contains the given set of error messages
   *
   * @param $messages
   *   array An array of texts to be checked
   *
   * @Then /^I should see the following <error messages>$/
   */
  public function iShouldSeeTheFollowingErrorMessages(TableNode $messages) {
    $steps = array();
    foreach ($messages->getHash() as $key => $value) {
      $message = trim($value['error messages']);
      $steps[] = new Then("I should see the error message \"$message\"");
    }
    return $steps;
  }

  /**
   * Checks if the current page does not contain the given error message
   *
   * @param $message
   *   string The text to be checked
   *
   * @Given /^I should not see the error message(?:| containing) "([^"]*)"$/
   */
  public function iShouldNotSeeTheErrorMessage($message) {
    $errorSelector = $this->getDrupalSelector('error_message_selector');
    $errorSelectorObj = $this->getSession()->getPage()->find("css", $errorSelector);
    if(!empty($errorSelectorObj)) {
      if (strpos(trim($errorSelectorObj->getText()), $message) !== FALSE) {
        throw new \Exception(sprintf("The page '%s' contains the error message '%s'", $this->getSession()->getCurrentUrl(), $message));
      }
    }
  }

  /**
   * Checks if the current page does not contain the given set error messages
   *
   * @param $messages
   *   array An array of texts to be checked
   *
   * @Then /^I should not see the following <error messages>$/
   */
  public function iShouldNotSeeTheFollowingErrorMessages(TableNode $messages) {
    $steps = array();
    foreach ($messages->getHash() as $key => $value) {
      $message = trim($value['error messages']);
      $steps[] = new Then("I should not see the error message \"$message\"");
    }
    return $steps;
  }

  /**
   * Checks if the current page contains the given success message
   *
   * @param $message
   *   string The text to be checked
   *
   * @Then /^I should see the success message(?:| containing) "([^"]*)"$/
   */
  public function iShouldSeeTheSuccessMessage($message) {
    $successSelector = $this->getDrupalSelector('success_message_selector');
    $successSelectorObj = $this->getSession()->getPage()->find("css", $successSelector);
    if(empty($successSelectorObj)) {
      throw new \Exception(sprintf("The page '%s' does not contain any success messages", $this->getSession()->getCurrentUrl()));
    }
    if (strpos(trim($successSelectorObj->getText()), $message) === FALSE) {
      throw new \Exception(sprintf("The page '%s' does not contain the success message '%s'", $this->getSession()->getCurrentUrl(), $message));
    }
  }

  /**
   * Checks if the current page contains the given set of success messages
   *
   * @param $message
   *   array An array of texts to be checked
   *
   * @Then /^I should see the following <success messages>$/
   */
  public function iShouldSeeTheFollowingSuccessMessages(TableNode $messages) {
    $steps = array();
    foreach ($messages->getHash() as $key => $value) {
      $message = trim($value['success messages']);
      $steps[] = new Then("I should see the success message \"$message\"");
    }
    return $steps;
  }

  /**
   * Checks if the current page does not contain the given set of success message
   *
   * @param $message
   *   string The text to be checked
   *
   * @Given /^I should not see the success message(?:| containing) "([^"]*)"$/
   */
  public function iShouldNotSeeTheSuccessMessage($message) {
    $successSelector = $this->getDrupalSelector('success_message_selector');
    $successSelectorObj = $this->getSession()->getPage()->find("css", $successSelector);
    if(!empty($successSelectorObj)) {
      if (strpos(trim($successSelectorObj->getText()), $message) !== FALSE) {
        throw new \Exception(sprintf("The page '%s' contains the success message '%s'", $this->getSession()->getCurrentUrl(), $message));
      }
    }
  }

  /**
   * Checks if the current page does not contain the given set of success messages
   *
   * @param $message
   *   array An array of texts to be checked
   *
   * @Then /^I should not see the following <success messages>$/
   */
  public function iShouldNotSeeTheFollowingSuccessMessages(TableNode $messages) {
    $steps = array();
    foreach ($messages->getHash() as $key => $value) {
      $message = trim($value['success messages']);
      $steps[] = new Then("I should not see the success message \"$message\"");
    }
    return $steps;
  }

  /**
   * Checks if the current page contain the given message
   *
   * @param $message
   *   string The message to be checked
   *
   * @Then /^I should see the message(?:| containing) "([^"]*)"$/
   */
  public function iShouldSeeTheMessage($message) {
    $msgSelector = $this->getDrupalSelector('message_selector');
    $msgSelectorObj = $this->getSession()->getPage()->find("css", $msgSelector);
    if(empty($msgSelectorObj)) {
      throw new \Exception(sprintf("The page '%s' does not contain any messages", $this->getSession()->getCurrentUrl()));
    }
    if (strpos(trim($msgSelectorObj->getText()), $message) === FALSE) {
      throw new \Exception(sprintf("The page '%s' does not contain the message '%s'", $this->getSession()->getCurrentUrl(), $message));
    }
  }

  /**
   * Checks if the current page does not contain the given message
   *
   * @param $message
   *   string The message to be checked
   *
   * @Then /^I should not see the message(?:| containing) "([^"]*)"$/
   */
  public function iShouldNotSeeTheMessage($message) {
    $msgSelector = $this->getDrupalSelector('message_selector');
    $msgSelectorObj = $this->getSession()->getPage()->find("css", $msgSelector);
    if(!empty($msgSelectorObj)) {
      if (strpos(trim($msgSelectorObj->getText()), $message) !== FALSE) {
        throw new \Exception(sprintf("The page '%s' contains the message '%s'", $this->getSession()->getCurrentUrl(), $message));
      }
    }
  }

  /**
   * Returns a specific css selector.
   *
   * @param $name
   *   string CSS selector name
   */
  public function getDrupalSelector($name) {
    $text = $this->getDrupalParameter('selectors');
    if (!isset($text[$name])) {
      throw new \Exception(sprintf('No such selector configured: %s', $name));
    }
    return $text[$name];
  }

  /**
   * @} End of defgroup "drupal extensions"
   */
}
