<?php

namespace App\Entity;


interface UserInterface
{
    public function getId();

    public function setUsername($username);

    public function getUsernameCanonical();

    public function setUsernameCanonical($usernameCanonical);

    public function setSalt($salt);

    public function getEmail();

    public function setEmail($email);

    public function getEmailCanonical();

    public function setEmailCanonical($emailCanonical);

    public function getPlainPassword();

    public function setPlainPassword($password);

    public function setPassword($password);

    public function isSuperAdmin();

    public function setEnabled($boolean);

    public function setSuperAdmin($boolean);

    public function getConfirmationToken();

    public function setConfirmationToken($confirmationToken);

    public function setPasswordRequestedAt(\DateTime $date = null);

    public function isPasswordRequestNonExpired($ttl);

    public function setLastLogin(\DateTime $time = null);

    /**
     * Never use this to check if this user has access to anything!
     *
     * Use the AuthorizationChecker, or an implementation of AccessDecisionManager
     * instead, e.g.
     *
     *         $authorizationChecker->isGranted('ROLE_USER');
     */
    public function hasRole($role);

    public function setRoles(array $roles);

    public function addRole($role);

    public function removeRole($role);

    public function getBaseCurrency(): string;

    public function getSettings(): UserSettings;

    public function setSettings(UserSettings $settings): static;
}
