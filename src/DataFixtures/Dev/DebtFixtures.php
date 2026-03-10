<?php
namespace App\DataFixtures\Dev;

use App\Entity\Account;
use App\Entity\BankCardAccount;
use App\Entity\CashAccount;
use App\Entity\Debt;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Entity\Income;
use App\Entity\IncomeCategory;
use App\Entity\InternetAccount;
use App\Entity\User;
use Carbon\CarbonImmutable;
use Doctrine\Persistence\ObjectManager;

/**
 * Creates 6 debts covering both directions (I lent / I borrowed) and various states.
 *
 * "I lent money"  → initial transaction is an Expense (money left my account)
 * "I borrowed"    → initial transaction is an Income  (money entered my account)
 */
class DebtFixtures extends BaseTransactionFixtures
{
  public function load(ObjectManager $manager): void
  {
    $user = $this->getReference('dev_user', User::class);
    $allowedCurrencies = $this->params->get('allowed_currencies');

    $this->disableListeners();

    /** @var BankCardAccount $monobankUah */
    $monobankUah = $this->getReference('account_monobank_uah', BankCardAccount::class);
    /** @var BankCardAccount $monobankEur */
    $monobankEur = $this->getReference('account_monobank_eur', BankCardAccount::class);
    /** @var InternetAccount $wiseEur */
    $wiseEur = $this->getReference('account_wise_eur', InternetAccount::class);
    /** @var BankCardAccount $privatbankUah */
    $privatbankUah = $this->getReference('account_privatbank_uah', BankCardAccount::class);
    /** @var CashAccount $cashUah */
    $cashUah = $this->getReference('account_cash_uah', CashAccount::class);

    $debtExpenseCat = $manager->getRepository(ExpenseCategory::class)->findOneBy(['name' => 'Debt']);
    $debtIncomeCat = $manager->getRepository(IncomeCategory::class)->findOneBy(['name' => 'Debt']);

    $now = CarbonImmutable::now();

    // ── 1. I lent 15 000 UAH to Dmytro — partially repaid (9 500 remaining) ──

    $debt1 = (new Debt())
      ->setDebtor('Dmytro Marchenko')
      ->setCurrency('UAH')
      ->setBalance('9500.00')
      ->setNote('Lent for car repair. Promised to return in 3 months.')
      ->setOwner($user)
      ->setCreatedAt($now->subMonths(4))
      ->setUpdatedAt($now->subWeeks(2));
    $manager->persist($debt1);
    $manager->flush();

    $manager->persist((new Expense())
      ->setAccount($monobankUah)->setCategory($debtExpenseCat)->setOwner($user)
      ->setAmount('15000.00')
      ->setConvertedValues($this->convertAmount(15000, 'UAH', $allowedCurrencies))
      ->setNote('Lent to Dmytro for car repair')
      ->setDebt($debt1)
      ->setExecutedAt($now->subMonths(4))
      ->setCreatedAt($now->subMonths(4))->setUpdatedAt($now->subMonths(4))
      ->setIsDraft(false));

    $manager->persist((new Income())
      ->setAccount($cashUah)->setCategory($debtIncomeCat)->setOwner($user)
      ->setAmount('5500.00')
      ->setConvertedValues($this->convertAmount(5500, 'UAH', $allowedCurrencies))
      ->setNote('Partial repayment from Dmytro')
      ->setDebt($debt1)
      ->setExecutedAt($now->subWeeks(2))
      ->setCreatedAt($now->subWeeks(2))->setUpdatedAt($now->subWeeks(2))
      ->setIsDraft(false));

    // ── 2. I lent 800 EUR to Iryna — not repaid yet ────────────────────────

    $debt2 = (new Debt())
      ->setDebtor('Iryna Kovalenko')
      ->setCurrency('EUR')
      ->setBalance('800.00')
      ->setNote('Emergency loan for medical bills. No repayment plan yet.')
      ->setOwner($user)
      ->setCreatedAt($now->subMonths(3))
      ->setUpdatedAt($now->subMonths(3));
    $manager->persist($debt2);
    $manager->flush();

    $manager->persist((new Expense())
      ->setAccount($monobankEur)->setCategory($debtExpenseCat)->setOwner($user)
      ->setAmount('800.00')
      ->setConvertedValues($this->convertAmount(800, 'EUR', $allowedCurrencies))
      ->setNote('Lent to Iryna for medical bills')
      ->setDebt($debt2)
      ->setExecutedAt($now->subMonths(3))
      ->setCreatedAt($now->subMonths(3))->setUpdatedAt($now->subMonths(3))
      ->setIsDraft(false));

    // ── 3. I borrowed 20 000 UAH from Mykola — not repaid yet ─────────────

    $debt3 = (new Debt())
      ->setDebtor('Mykola Petrov')
      ->setCurrency('UAH')
      ->setBalance('20000.00')
      ->setNote('Borrowed for apartment deposit. Will repay in 2 installments.')
      ->setOwner($user)
      ->setCreatedAt($now->subMonths(2))
      ->setUpdatedAt($now->subMonths(2));
    $manager->persist($debt3);
    $manager->flush();

    $manager->persist((new Income())
      ->setAccount($monobankUah)->setCategory($debtIncomeCat)->setOwner($user)
      ->setAmount('20000.00')
      ->setConvertedValues($this->convertAmount(20000, 'UAH', $allowedCurrencies))
      ->setNote('Borrowed from Mykola for apartment deposit')
      ->setDebt($debt3)
      ->setExecutedAt($now->subMonths(2))
      ->setCreatedAt($now->subMonths(2))->setUpdatedAt($now->subMonths(2))
      ->setIsDraft(false));

    // ── 4. I borrowed 500 EUR from Olena — partially repaid (200 remaining) ─

    $debt4 = (new Debt())
      ->setDebtor('Olena Sydorenko')
      ->setCurrency('EUR')
      ->setBalance('200.00')
      ->setNote('Borrowed for conference trip. Paying back monthly.')
      ->setOwner($user)
      ->setCreatedAt($now->subMonths(5))
      ->setUpdatedAt($now->subMonths(1));
    $manager->persist($debt4);
    $manager->flush();

    $manager->persist((new Income())
      ->setAccount($wiseEur)->setCategory($debtIncomeCat)->setOwner($user)
      ->setAmount('500.00')
      ->setConvertedValues($this->convertAmount(500, 'EUR', $allowedCurrencies))
      ->setNote('Borrowed from Olena for conference')
      ->setDebt($debt4)
      ->setExecutedAt($now->subMonths(5))
      ->setCreatedAt($now->subMonths(5))->setUpdatedAt($now->subMonths(5))
      ->setIsDraft(false));

    $manager->persist((new Expense())
      ->setAccount($wiseEur)->setCategory($debtExpenseCat)->setOwner($user)
      ->setAmount('150.00')
      ->setConvertedValues($this->convertAmount(150, 'EUR', $allowedCurrencies))
      ->setNote('Repaid Olena — instalment 1')
      ->setDebt($debt4)
      ->setExecutedAt($now->subMonths(3))
      ->setCreatedAt($now->subMonths(3))->setUpdatedAt($now->subMonths(3))
      ->setIsDraft(false));

    $manager->persist((new Expense())
      ->setAccount($wiseEur)->setCategory($debtExpenseCat)->setOwner($user)
      ->setAmount('150.00')
      ->setConvertedValues($this->convertAmount(150, 'EUR', $allowedCurrencies))
      ->setNote('Repaid Olena — instalment 2')
      ->setDebt($debt4)
      ->setExecutedAt($now->subMonths(1))
      ->setCreatedAt($now->subMonths(1))->setUpdatedAt($now->subMonths(1))
      ->setIsDraft(false));

    // ── 5. Viktor covered 3 000 UAH grocery run — I owe him ───────────────

    $debt5 = (new Debt())
      ->setDebtor('Viktor Bondarenko')
      ->setCurrency('UAH')
      ->setBalance('3000.00')
      ->setNote('Covered grocery run when my card was blocked.')
      ->setOwner($user)
      ->setCreatedAt($now->subMonths(1))
      ->setUpdatedAt($now->subMonths(1));
    $manager->persist($debt5);
    $manager->flush();

    $manager->persist((new Income())
      ->setAccount($cashUah)->setCategory($debtIncomeCat)->setOwner($user)
      ->setAmount('3000.00')
      ->setConvertedValues($this->convertAmount(3000, 'UAH', $allowedCurrencies))
      ->setNote('Viktor paid for groceries (I owe him)')
      ->setDebt($debt5)
      ->setExecutedAt($now->subMonths(1))
      ->setCreatedAt($now->subMonths(1))->setUpdatedAt($now->subMonths(1))
      ->setIsDraft(false));

    // ── 6. Serhiy owed me 1 200 UAH — fully repaid (closed) ───────────────

    $debt6 = (new Debt())
      ->setDebtor('Serhiy Lysenko')
      ->setCurrency('UAH')
      ->setBalance('0.00')
      ->setNote('Short-term loan for taxi. Fully returned.')
      ->setOwner($user)
      ->setCreatedAt($now->subMonths(7))
      ->setUpdatedAt($now->subMonths(6));
    $manager->persist($debt6);
    $manager->flush();

    $manager->persist((new Expense())
      ->setAccount($privatbankUah)->setCategory($debtExpenseCat)->setOwner($user)
      ->setAmount('1200.00')
      ->setConvertedValues($this->convertAmount(1200, 'UAH', $allowedCurrencies))
      ->setNote('Lent to Serhiy for taxi')
      ->setDebt($debt6)
      ->setExecutedAt($now->subMonths(7))
      ->setCreatedAt($now->subMonths(7))->setUpdatedAt($now->subMonths(7))
      ->setIsDraft(false));

    $manager->persist((new Income())
      ->setAccount($privatbankUah)->setCategory($debtIncomeCat)->setOwner($user)
      ->setAmount('1200.00')
      ->setConvertedValues($this->convertAmount(1200, 'UAH', $allowedCurrencies))
      ->setNote('Serhiy returned the loan in full')
      ->setDebt($debt6)
      ->setExecutedAt($now->subMonths(6))
      ->setCreatedAt($now->subMonths(6))->setUpdatedAt($now->subMonths(6))
      ->setIsDraft(false));

    $manager->flush();
    $this->enableListeners();
  }

  public function getDependencies(): array
  {
    return array_merge(parent::getDependencies(), [AccountFixtures::class]);
  }
}