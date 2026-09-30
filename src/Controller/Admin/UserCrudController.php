<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Service\AccessRevoker;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class UserCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly AccessRevoker $revoker,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Utilisateur')
            ->setEntityLabelInPlural('Utilisateurs')
            ->setSearchFields(['email', 'displayName'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('applications')->add('enabled');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield EmailField::new('email', 'E-mail');
        yield TextField::new('displayName', 'Nom');
        yield AssociationField::new('applications', 'Applications autorisées')
            ->setFormTypeOptions(['by_reference' => false])
            ->autocomplete()
            ->setHelp('Applications auxquelles cet utilisateur peut se connecter.');
        yield BooleanField::new('enabled', 'Actif')->renderAsSwitch(false);
        yield ChoiceField::new('roles', 'Rôles')
            ->setChoices(['Administrateur' => 'ROLE_ADMIN'])
            ->allowMultipleChoices()
            ->renderExpanded()
            ->hideOnIndex();
        yield TextField::new('plainPassword', Crud::PAGE_NEW === $pageName ? 'Mot de passe' : 'Nouveau mot de passe')
            ->setFormType(PasswordType::class)
            ->setFormTypeOptions(['required' => Crud::PAGE_NEW === $pageName, 'attr' => ['autocomplete' => 'new-password']])
            ->setHelp(Crud::PAGE_EDIT === $pageName ? 'Laisser vide pour ne pas changer.' : '')
            ->onlyOnForms();
        yield DateTimeField::new('createdAt', 'Inscrit le')->hideOnForm();
        yield DateTimeField::new('lastLoginAt', 'Dernière connexion')->hideOnForm();
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->hashPassword($entityInstance);
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        \assert($entityInstance instanceof User);
        $this->hashPassword($entityInstance);

        // Applications retirées : on révoque les jetons en cours pour ces applications.
        $applications = $entityInstance->getApplications();
        $removed = $applications instanceof PersistentCollection ? $applications->getDeleteDiff() : [];

        parent::updateEntity($entityManager, $entityInstance);

        if (!$entityInstance->isEnabled()) {
            $this->revoker->revokeForUser($entityInstance);
        } else {
            foreach ($removed as $application) {
                $this->revoker->revokeForUser($entityInstance, $application);
            }
        }
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        \assert($entityInstance instanceof User);
        $this->revoker->revokeForUser($entityInstance);
        parent::deleteEntity($entityManager, $entityInstance);
    }

    private function hashPassword(User $user): void
    {
        $plain = $user->getPlainPassword();
        if (null !== $plain && '' !== $plain) {
            $user->setPassword($this->hasher->hashPassword($user, $plain));
            $user->eraseCredentials();
        }
    }
}
