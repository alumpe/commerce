<?php
namespace Craft;

use Commerce\Helpers\CommerceDbHelper;

/**
 * Variant service.
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @copyright Copyright (c) 2015, Pixel & Tonic, Inc.
 * @license   http://craftcommerce.com/license Craft Commerce License Agreement
 * @see       http://craftcommerce.com
 * @package   craft.plugins.commerce.services
 * @since     1.0
 */
class Commerce_VariantService extends BaseApplicationComponent
{
	/**
	 * @param int $id
	 *
	 * @return Commerce_VariantModel
	 */
	public function getById ($id)
	{
		return craft()->elements->getElementById($id, 'Commerce_Variant');
	}

	/**
	 * @param int $id
	 */
	public function deleteById ($id)
	{
		craft()->elements->deleteElementById($id);
	}

	/**
	 * @param int $productId
	 */
	public function deleteAllByProductId ($productId)
	{
		$variants = $this->getAllByProductId($productId);
		foreach ($variants as $variant)
		{
			$this->deleteVariant($variant);
		}
	}

	/**
	 * @param int $id
	 *
	 * @return Commerce_VariantModel[]
	 */
	public function getAllByProductId ($id)
	{
		$criteria = ['productId' => $id];
		$variants = craft()->elements->getCriteria('Commerce_Variant',
			$criteria)->find();

		return $variants;
	}

	/**
	 * @param $variant
	 */
	public function deleteVariant ($variant)
	{
		craft()->elements->deleteElementById($variant->id);
	}

	/**
	 * Apply sales, associated with the given product, to all given variants
	 *
	 * @param Commerce_VariantModel[] $variants
	 * @param Commerce_ProductModel   $product
	 */
	public function applySales (array $variants, Commerce_ProductModel $product)
	{

		// set salePrice to be price at default
		foreach ($variants as $variant)
		{
			$variant->salePrice = $variant->price;
		}

		// Don't apply sales when product is not persisted.
		if ($product->id)
		{
			$sales = craft()->commerce_sale->getForProduct($product);

			foreach ($sales as $sale)
			{
				foreach ($variants as $variant)
				{
					// only apply sales to promotable products
					if ($product->promotable)
					{
						$variant->salePrice = $variant->price + $sale->calculateTakeoff($variant->price);
						if ($variant->salePrice < 0)
						{
							$variant->salePrice = 0;
						}
					}
				}
			}
		}
	}

	/**
	 * Save a model into DB
	 *
	 * @param BaseElementModel $model
	 *
	 * @return bool
	 * @throws \CDbException
	 * @throws \Exception
	 */
	public function save (BaseElementModel $model)
	{
		$productTypeId = craft()->db->createCommand()
			->select('typeId')
			->from('commerce_products')
			->where('id=:id', [':id' => $model->productId])
			->queryScalar();

		$productType = craft()->commerce_productType->getById($productTypeId);

		if ($model->id)
		{
			$record = Commerce_VariantRecord::model()->findById($model->id);

			if (!$record)
			{
				throw new HttpException(404);
			}
		}
		else
		{
			$record = new Commerce_VariantRecord();
		}
		/* @var Commerce_VariantModel $model */
		$record->isImplicit = $model->isImplicit;
		$record->productId = $model->productId;

		// We dont ask for a sku when dealing with a product with variants
		if ($model->isImplicit && $productType->hasVariants)
		{
			$model->sku = 'implicitSkuOfProductId'.$model->productId;
			$record->sku = $model->sku;
		}
		else
		{
			$record->sku = $model->sku;
		}

		if (!$productType->titleFormat)
		{
			$productType->titleFormat = "{sku}";
		}

		// implicit variant has no custom field data so play it safe and default it to sku.
		if ($model->isImplicit)
		{
			$productType->titleFormat = "{sku}";
		}

		$model->getContent()->title = craft()->templates->renderObjectTemplate($productType->titleFormat, $model);

		$record->price = $model->price;
		$record->width = $model->width;
		$record->height = $model->height;
		$record->length = $model->length;
		$record->weight = $model->weight;
		$record->minQty = $model->minQty;
		$record->maxQty = $model->maxQty;
		$record->stock = $model->stock;
		$record->unlimitedStock = $model->unlimitedStock;

		if (!$productType->hasDimensions)
		{
			$record->width = $model->width = 0;
			$record->height = $model->height = 0;
			$record->length = $model->length = 0;
			$record->weight = $model->weight = 0;
		}

		if ($model->unlimitedStock && $record->stock == "")
		{
			$model->stock = 0;
			$record->stock = 0;
		}

		$record->validate();
		$model->addErrors($record->getErrors());

		CommerceDbHelper::beginStackedTransaction();
		try
		{
			if (!$model->hasErrors())
			{
				if (craft()->commerce_purchasable->saveElement($model))
				{
					$record->id = $model->id;
					$record->save(false);
					CommerceDbHelper::commitStackedTransaction();

					return true;
				}
			}
		}
		catch (\Exception $e)
		{
			CommerceDbHelper::rollbackStackedTransaction();
			throw $e;
		}

		CommerceDbHelper::rollbackStackedTransaction();

		return false;
	}

	/**
	 * Update Stock count from completed order
	 *
	 * @param Event $event
	 */
	public function orderCompleteHandler (Event $event)
	{
		/** @var Commerce_OrderModel $order */
		$order = $event->params['order'];

		foreach ($order->lineItems as $lineItem)
		{
			/** @var Commerce_VariantRecord $record */
			$record = Commerce_VariantRecord::model()->findByAttributes(['id' => $lineItem->purchasableId]);
			if (!$record->unlimitedStock)
			{
				$record->stock = $record->stock - $lineItem->qty;
				$record->save(false);
			}
		}
	}

}