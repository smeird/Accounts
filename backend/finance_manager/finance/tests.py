from django.test import TestCase
from .models import Account

class AccountModelTest(TestCase):
    def test_create_account(self):
        account = Account.objects.create(name='Checking')
        self.assertEqual(account.name, 'Checking')
