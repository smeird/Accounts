from django.db import models

class Account(models.Model):
    name = models.CharField(max_length=100)

class Category(models.Model):
    name = models.CharField(max_length=100)

class Tag(models.Model):
    name = models.CharField(max_length=100)

class TransactionGroup(models.Model):
    name = models.CharField(max_length=100)

class Transaction(models.Model):
    account = models.ForeignKey(Account, on_delete=models.CASCADE)
    date = models.DateField()
    amount = models.DecimalField(max_digits=10, decimal_places=2)
    description = models.CharField(max_length=255)
    category = models.ForeignKey(Category, on_delete=models.SET_NULL, null=True)
    tag = models.ForeignKey(Tag, on_delete=models.SET_NULL, null=True)
    group = models.ForeignKey(TransactionGroup, on_delete=models.SET_NULL, null=True)
    ofx_id = models.CharField(max_length=255, unique=True)
