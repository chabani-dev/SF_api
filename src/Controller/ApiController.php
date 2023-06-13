<?php

namespace App\Controller;

use App\Entity\Post;
use App\Entity\User;
use App\Entity\Category;
use App\Repository\PostRepository;
use App\Repository\UserRepository;
use App\Repository\CategoryRepository;
use phpDocumentor\Reflection\Types\Integer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;

class ApiController extends AbstractController
{
    /**
     * @Route("/api/posts", name="app_api_post_index", methods={"GET"})
     */
    public function post_index(PostRepository $postRepository): Response
    {
        // la méthode json() de AbstractController permet de regrouper la serialization et la construction de la réponse au sein d'une seule ligne de code (nous n'avons plus besoin du service SerializerInterface), en remplaçant $posts par sa valeur on obtient en une seule ligne :

        return $this->json($postRepository->findAll(), 200, [], ["groups" => "post"]);
    }

    /**
     * @Route("/api/post/{id}", name="app_api_post_by_id", methods={"GET"})
     */
    public function post_by_id(String $id, PostRepository $postRepository): Response
    {
        $post = $postRepository->find($id);
        if($post!=null) {
            return $this->json($post, 200, [], ["groups" => "post"]);
        } else {
            return $this->json([
                "status"=>404,
                "message"=>"Article introuvable"
            ], 404);
        }
    }

    /**
     * @Route("/api/posts_by_author/{login}", name="app_api_posts_by_author", methods={"GET"})
     */
    public function posts_by_author(String $login, UserRepository $userRepository, PostRepository $postRepository): Response
    {
        $author = $userRepository->findOneBy(["login" => $login]);
        return $this->json($postRepository->findBy(["author" => $author]), 200, [], ["groups" => "post"]);
    }

    /**
     * @Route("/api/posts_by_category/{name}", name="app_api_posts_by_category", methods={"GET"})
     */
    public function posts_by_category(String $name, CategoryRepository $categoryRepository, PostRepository $postRepository): Response
    {
        $category = $categoryRepository->findOneBy(["name" => $name]);
        return $this->json($postRepository->findBy(["category" => $category]), 200, [], ["groups" => "post"]);
    }

    /**
     * @Route("/api/users", name="app_api_user_index", methods={"GET"})
     */
    public function user_index(UserRepository $userRepository, NormalizerInterface $normalizer):Response
    {
        $users = $userRepository->findAll();
        $normalizedUsers = $normalizer->normalize($users, null, ['groups' => 'user']);
        $json_users = json_encode($normalizedUsers);
        $response = new Response($json_users, 200, [
            "Content-Type" => "application/json",
        ]);
        return $response;
    }

    //!--------------------------------------------------------------

    /**
     * @Route("/api/user/new", name="app_api_create_user", methods={"POST"})
     */
    public function create_user(Request $request, SerializerInterface $serializer, UserRepository $userRepository, ValidatorInterface $validator): Response
    {
        // on récupère le corps de la requête (ici en json)
        $json_received = $request->getContent();
        // on met en place un try & catch qui va permettre de traiter les erreurs éventuelles
        // try = tout va bien (le corps de la requête ne présente pas d'erreur de syntaxe)
        try {
            //on utilise la 'deserialization' pour transformer le json en objet de type User
            $user = $serializer->deserialize($json_received, User::class, 'json');
            //dans cet objet on renseigne les propriétés updatedAt et createdAt
            $user->setUpdatedAt(new \DatetimeImmutable('now'));
            $user->setCreatedAt(new \DatetimeImmutable('now'));

            //on utilise le service ValidatorInterface de Symfony. Pour celà il faut au préalable ajouter des contraintes dans les entités concernées (cf entity User)
            //la méthode validate de ValidatorInterface appliquée à $user nous retourne un tableau avec les erreurs éventuelles
            $errors = $validator->validate($user);

            // si le tableau des erreurs n'est pas vide :
            if(count($errors) > 0){
                // on retourne une réponse avec le statut 400 et un objet en json qui contient le détail des erreurs
                return $this->json($errors, 400);
            }

            // si le tableau des erreurs est vide on peut persister les données
            $userRepository->add($user, true);
            // on retourne une réponse avec le user créé et un status 201
            return $this->json($user, 201, [], ["groups" => "user"]);

        // le catch sert à traiter le cas où le corps de la requête présente une erreur de syntaxe            
        } catch (NotEncodableValueException $e) {
            //dans ce cas on retourne une erreur 400 et un objet json avec un message et un status
            return $this->json([
                "status" => 400,
                "message" => $e->getMessage()
            ], 400);
        }
    }

    /**
     * @Route("/api/category/new", name="app_api_create_category", methods={"POST"})
     */
    public function create_category(Request $request, CategoryRepository $categoryRepository, SerializerInterface $serializer, ValidatorInterface $validator): Response
    {
        $json = $request->getContent();
        try {
            $category = $serializer->deserialize($json, Category::class, 'json');
            // on teste si la catégorie existe déjà
            $name = $category->getName();
            $doublon = $categoryRepository->findOneBy(["name" => $name]);
            if($doublon != null){
                return $this->json([
                    "status"=>400,
                    "message"=>"la catégorie existe déjà",
                ], 400);
            }
            
            $category->setCreatedAt(new \DatetimeImmutable('now'));
            $category->setUpdatedAt(new \DatetimeImmutable('now'));

            $errors = $validator->validate($category);
            if(count($errors) > 0){
                return $this->json($errors, 400);
            }

            $categoryRepository->add($category, true);
    
            return $this->json($category, 201, [], ["groups" => "post"]);
        } catch (NotEncodableValueException $e) {
            return $this->json([
                "status"=>400,
                "message"=>$e->getMessage(),
            ], 400);
        }
    }

    /**
     * @Route("/api/post/new", name="app_api_create_post", methods={"POST"})
     */
    public function create_post(Request $request, PostRepository $postRepository, UserRepository $userRepository, CategoryRepository $categoryRepository, SerializerInterface $serializer, ValidatorInterface $validator): Response
    {
        $json = $request->getContent();
        try {
            $post = $serializer->deserialize($json, Post::class, 'json');
            // on a besoin de récupérer l'auteur et la catégorie qui sont associées au post que l'on est en train de créer. Ces information sont transmises dans le body de la requête grâce aux propriétés login de author et name de category

            // on récupère donc le login de l'author du post
            $authorLogin = $post->getAuthor()->getLogin();
            //on effectue une recherche de ce user grâce à la méthode findOneBy() et le critère "login"
            $author = $userRepository->findOneBy(["login"=>$authorLogin]);
            
            // même chose avec le name de la category du post
            $categoryName = $post->getCategory()->getName();
            $category = $categoryRepository->findOneBy(["name"=>$categoryName]);
    
            // il faut s'assurer que l'on a bien récupéré un auteur et une catégorie avant de continuer la persistence des données : envoi d'un message d'erreur si ce n'est pas le cas.
            if($author==null || $category==null){
                return $this->json([
                    "status"=>400,
                    "message"=>"Informations manquantes pour créer le post",
                ], 400);
            }

            $post->setAuthor($author);
            $post->setCategory($category);
            $post->setCreatedAt(new \DatetimeImmutable('now'));
            $post->setUpdatedAt(new \DatetimeImmutable('now'));
    
            $errors = $validator->validate($post);
            if(count($errors) > 0){
                return $this->json($errors, 400);
            }

            $postRepository->add($post, true);
            return $this->json($post, 201, [], ["groups" => "post"]);
        } catch (NotEncodableValueException $e) {
            return $this->json([
                "status"=>400,
                "message"=>$e->getMessage(),
            ], 400);
        }
    }
}
