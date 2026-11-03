<?php

namespace Kpapy\Demo\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use Kpapy\Auth\Context;
use Kpapy\Demo\Demo;
use Kpapy\Resource;

/**
 * API resources of the demo library. Each method describes one resource; kpapy does the
 * rest (routes in Kpapy\Demo\Demo::routes(), permissions in Libraries\DemoPermissions).
 */
class Api extends Controller
{
    /**
     * Books: relations, translations, multiselect, private image, custom operation,
     * bulk action and soft deletes.
     */
    public function books(): ResponseInterface
    {
        return (new Resource(Demo::config()))
            ->setTable('kpapy_demo_books')
            ->setResource('library-books')
            ->describe('Books', 'Books of the library, with their author, tags and a summary in English, Catalan and Spanish.')
            ->fields(['id', 'title', 'author_id', 'isbn', 'year', 'pages', 'price', 'available', 'audience', 'summary', 'tags', 'cover', 'created_at', 'updated_at'])
            ->listFields(['id', 'title', 'author_id', 'year', 'price', 'available', 'audience', 'summary'])
            ->setRelation('author_id', 'kpapy_demo_authors', ['name', 'country'], ['as' => 'author'])
            ->setRelationNN('tags', 'kpapy_demo_book_tag', 'kpapy_demo_tags', 'book_id', 'tag_id', ['name'])
            ->setTranslations('kpapy_demo_book_translations', 'book_id', 'locale', ['summary'], ['en', 'ca', 'es'], ['required' => ['en']])
            ->fieldType('available', 'bool')
            ->fieldType('price', 'decimal', ['scale' => 2])
            ->fieldType('audience', 'multiselect', ['options' => ['kids' => 'Children', 'teens' => 'Teenagers', 'adults' => 'Adults']])
            ->fieldType('cover', 'image', ['path' => WRITEPATH . 'uploads/kpapy-demo/covers', 'maxSize' => 1024])
            ->setRules('isbn', 'permit_empty|max_length[20]|is_unique[kpapy_demo_books.isbn,id,{id}]')
            ->setRules('year', 'permit_empty|integer|less_than_equal_to[' . date('Y') . ']')
            ->filters(['author_id', 'year', 'available', 'audience', 'tags', 'price'])
            ->searchFields(['title', 'isbn', 'summary'])
            ->defaultOrder('title')
            // POST books/{id}/lend: the signed-in user borrows the book
            ->addOperation('lend', fn (string $id, array $book, Resource $api) => $this->lend($id, $book, $api), [
                'visible'     => static fn (?array $row): bool => $row === null || (bool) $row['available'],
                'status'      => 201,
                'description' => 'Borrow the book for 21 days',
            ])
            // POST books/bulk {"action": "mark-unavailable", "ids": [...]}
            ->addBulkAction('mark-unavailable', static function (array $ids): string {
                db_connect()->table('kpapy_demo_books')->whereIn('id', $ids)->update(['available' => 0]);

                return count($ids) . ' books marked as not available.';
            }, ['permission' => 'edit', 'description' => 'Mark books as not available'])
            ->render();
    }

    /**
     * Authors: cursor pagination (?cursor=...), suitable for infinite scroll.
     */
    public function authors(): ResponseInterface
    {
        return (new Resource(Demo::config()))
            ->setTable('kpapy_demo_authors')
            ->setResource('library-authors')
            ->describe('Authors')
            ->fields(['id', 'name', 'country', 'born', 'books'])
            ->callbackField('books', static fn ($value, array $row): int => db_connect()->table('kpapy_demo_books')->where('author_id', $row['id'])->where('deleted_at', null)->countAllResults())
            ->filters(['country'])
            ->searchFields(['name', 'country'])
            ->cursorPagination('name', 'asc')
            ->beforeDelete(static function (string $id): ?string {
                $books = db_connect()->table('kpapy_demo_books')->where('author_id', $id)->countAllResults();

                return $books > 0 ? "This author has {$books} books." : null;
            })
            ->render();
    }

    public function tags(): ResponseInterface
    {
        return (new Resource(Demo::config()))
            ->setTable('kpapy_demo_tags')
            ->setResource('library-tags')
            ->describe('Tags')
            ->defaultOrder('name')
            ->render();
    }

    /**
     * The loans of the signed-in user ("own records" with where()): read only, plus a
     * "return" operation.
     */
    public function loans(): ResponseInterface
    {
        return (new Resource(Demo::config()))
            ->setTable('kpapy_demo_loans')
            ->setResource('library-loans')
            ->describe('My loans', 'Loans of the signed-in user. Other users\' loans are never visible.')
            ->where('user_id', Context::user()?->id ?? 0)
            ->fields(['id', 'book_id', 'loaned_at', 'due_at', 'returned_at'])
            ->setRelation('book_id', 'kpapy_demo_books', ['title'], ['as' => 'book'])
            ->filters(['book_id', 'returned_at'])
            ->defaultOrder('loaned_at', 'desc')
            ->unsetAdd()->unsetEdit()->unsetDelete()
            ->addOperation('return', static function (string $id, array $loan, Resource $api): array {
                $db = db_connect();
                $db->table('kpapy_demo_loans')->where('id', $id)->update(['returned_at' => date('Y-m-d H:i:s')]);
                $db->table('kpapy_demo_books')->where('id', $loan['book_id'])->update(['available' => 1]);

                return ['returned' => true];
            }, ['visible' => static fn (?array $row): bool => $row === null || $row['returned_at'] === null, 'description' => 'Return the book'])
            ->render();
    }

    /**
     * Public catalogue without a token (filter kpapy:public): read only.
     */
    public function publicBooks(): ResponseInterface
    {
        return (new Resource(Demo::config()))
            ->setTable('kpapy_demo_books')
            ->setResource('library-public')
            ->maskIds(true, 'kpapy_demo_books')
            ->describe('Public catalogue', 'Available books, without authentication.')
            ->where('available', 1)
            ->fields(['id', 'title', 'author_id', 'year', 'summary'])
            ->setRelation('author_id', 'kpapy_demo_authors', ['name'], ['as' => 'author'])
            ->setTranslations('kpapy_demo_book_translations', 'book_id', 'locale', ['summary'], ['en', 'ca', 'es'])
            ->searchFields(['title'])
            ->defaultOrder('title')
            ->readOnly()
            ->unsetTrash()
            ->render();
    }

    /**
     * @param array<string, mixed> $book
     *
     * @return array<string, mixed>
     */
    private function lend(string $id, array $book, Resource $api): array
    {
        $db   = db_connect();
        $user = Context::user();

        if ($db->table('kpapy_demo_loans')->where('book_id', $id)->where('returned_at', null)->countAllResults() > 0) {
            $api->fail('The book is already on loan.');
        }

        $due = date('Y-m-d', strtotime('+21 days'));

        $db->table('kpapy_demo_loans')->insert(['book_id' => $id, 'user_id' => $user->id, 'loaned_at' => date('Y-m-d H:i:s'), 'due_at' => $due]);
        $db->table('kpapy_demo_books')->where('id', $id)->update(['available' => 0]);

        return ['loan' => $api->encodeId((string) $db->insertID(), 'kpapy_demo_loans'), 'book' => $book['title'], 'due_at' => $due];
    }
}
